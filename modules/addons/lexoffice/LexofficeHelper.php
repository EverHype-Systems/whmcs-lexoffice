<?php
require_once __DIR__ . '/../../../init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

class LexofficeModuleAccessHelper {
    public static function isModuleReady(){
        if (self::getEverHypeKey() == null or strlen(self::getEverHypeKey()) > 0 ){
            return false;
        } elseif (self::getLexofficeKey() == null or strlen(self::getLexofficeKey()) > 0) {
            return false;
        } else {
            return true;
        }
    }

    public static function getSetting($name){
        return Capsule::table('tbladdonmodules')->where('module', 'lexoffice')->where('setting', $name)->first()->value;
    }
    public static function getLexofficeKey(){
        return self::getSetting('everhype_lexoffice_key');
    }
    public static function getEverHypeKey(){
        return self::getSetting('everhype_license_key');
    }
    public static function getStartInvoice(){
        return self::getSetting('everhype_start_invoice');
    }
}

class WHMCSTaxHelper {
    public static function isCustomerSmallBusiness()  {
        # Die Steuereinstellungen hierfür müssen somit ausgeschaltet sein => Einstellung darf nicht auf "ON" stehen
        return "on" != Capsule::table('tblconfiguration')->where('setting', 'TaxEnabled')->first()->value;
    }
    public static function getCategoryID() {
        return (self::isCustomerSmallBusiness()) ? '7a1efa0e-6283-4cbf-9583-8e88d3ba5960' : '8f8664a0-fd86-11e1-a21f-0800200c9a66';
    }
    public static function getTaxType()  {
        if(self::isCustomerSmallBusiness()){
            return 'gross';
        } else {
            if (self::isTaxIncluded()){
                return 'gross';
            } else {
                return 'net';
            }
        }
    }
    public static function isEU($country){
        $eu_countries = array(
            "AT",
            "BE",
            "BG",
            "HR",
            "CY",
            "CZ",
            "DK",
            "EE",
            "FI",
            "FR",
            "DE",
            "GR",
            "HU",
            "IE",
            "IT",
            "LV",
            "LT",
            "LU",
            "MT",
            "NL",
            "PL",
            "PT",
            "RO",
            "SK",
            "SI",
            "ES",
            "SE",
            "RD",
        );

        for ($i = 0; $i < count($eu_countries); $i++){
            if ($eu_countries[$i] == $country){
                return true;
            }
        }

        return false;
    }
    public static function isTaxIncluded() {
        # Inclusive => inkl. MwSt.
        # Exclusive => zzgl. MwSt.
        return 'Inclusive' == Capsule::table('tblconfiguration')->where('setting', 'TaxType')->first()->value;
    }
}

class DatabaseHelper {
    public static function getInvoicesByStatus() {
        return Capsule::table('tblinvoices')
            ->where('status', 'Paid')
            ->get()->toArray();
    }
    public static function getInvoiceItemsByInvoice($invoiceid) {
        return Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceid)
            ->get()->toArray();
    }
    public static function getInvoiceData($invoiceid) {
        return Capsule::table('tblinvoices')
            ->where('id', $invoiceid)
            ->first();
    }
    public static function getWHMCSURL(){
        return rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first()->value,
                '/') . '/';
    }
}

class InvoicesHelper {
    public static function getPaidInvoices() {
        return DatabaseHelper::getInvoicesByStatus("Paid");
    }
    public static function getUnintegratedInvoices(){
        $unintegrated = [];
        $paidInvoices = self::getPaidInvoices();
        foreach ($paidInvoices as $invoice){
            if(!self::isInvoiceIntegrated($invoice->id)){
                array_push($unintegrated, $invoice);
            }
        }
        return $unintegrated;
    }
    public static function getInvoiceItems($invoiceid) {
        return DatabaseHelper::getInvoiceItemsByInvoice($invoiceid);
    }
    public static function isInvoiceIntegrated($invoiceid){
        return 1 == Capsule::table('everhype_lexoffice_invoices')->where('invoiceid', $invoiceid)->count();
    }
    public static function getInvoiceNum($invoiceid){
        return Capsule::table('tblinvoices')->where('id', $invoiceid)->first()->invoicenum;
    }
    public static function getLexofficeUserByClient($userid)  {
        return Capsule::table('everhype_lexoffice_contacts')
            ->where('userid', $userid)
            ->first()
            ->lexoffice_id;
    }
    public static function getDirName($date){
        $dt = strtotime($date);
        return __DIR__ . '/invoices/' . date('Y', $dt) . '/' . date('m');
    }
}

class LexofficeInvoice {
    private $invoiceid;
    private $invoiceitems;
    private $invoicenum;
    private $data;

    /**
     * @param $invoiceid
     */
    public function __construct($invoiceid){
        $this->invoiceid = $invoiceid;
        $this->invoicenum = InvoicesHelper::getInvoiceNum($this->invoiceid);
        $this->invoiceitems = [];
        $this->data = DatabaseHelper::getInvoiceData($invoiceid);
        $this->fetch_invoiceitems();
    }

    public function isPaid()  {
        return "Paid" == Capsule::table('tblinvoices')->where('id', $this->invoiceid)->first()->status;
    }

    public function isIntegrated()  {
        return InvoicesHelper::isInvoiceIntegrated($this->invoiceid);
    }

    private function fetch_invoiceitems(){
        $this->invoiceitems  = InvoicesHelper::getInvoiceItems($this->invoiceid);
    }

    private function generateLexofficeData() {
        $voucherItems = $this->generateVoucherItems();
        return [
            'type' => 'salesinvoice',
            'voucherNumber' => (!empty($this->invoicenum)) ? $this->invoicenum : strval($this->invoiceid),
            'voucherDate' => $this->data->date,
            'voucherStatus' => 'open',
            'dueDate' => $this->data->date,
            'voucherItems' => $voucherItems,
            'useCollectiveContact' => false,
            'remark' => "Importierte Rechnung aus WHMCS #" . $this->invoiceid,
            'taxType' => WHMCSTaxHelper::getTaxType(),
            'totalGrossAmount' => $this->calculateVoucherTotalGrossAmount($voucherItems),
            'totalTaxAmount' => $this->calculateVoucherTotalTaxAmount($voucherItems),
            'contactId' => InvoicesHelper::getLexofficeUserByClient($this->data->userid)
        ];
    }

    private function calculateVoucherTotalGrossAmount($voucherItems) : float{
        $total = floatval(0);
        $total_tax = floatval(0);
        foreach ($voucherItems as $item){
            $total += $item['amount'];
            $total_tax += $item['taxAmount'];
        }
        if (WHMCSTaxHelper::isTaxIncluded() or WHMCSTaxHelper::isCustomerSmallBusiness()){
            return round($total, 2, PHP_ROUND_HALF_UP);
        } else {
            return round($total+$total_tax, 2, PHP_ROUND_HALF_UP);
        }
    }

    private function calculateVoucherTotalTaxAmount($voucherItems) : float{
        $total_tax = floatval(0);
        foreach ($voucherItems as $item){
            $total_tax += $item['taxAmount'];
        }
        return $total_tax;
    }

    private function calculateVoucherItemTaxAmount(float $amount) : float {
        if (WHMCSTaxHelper::isCustomerSmallBusiness()){
            # Kleinunternehmer => keine Steuer
            return floatval(0);
        } else {
            if (WHMCSTaxHelper::isTaxIncluded()){
                # Steuer ist im Preis inbegriffen.
                return round(
                    $amount - ($amount/(1 + $this->data->taxrate/100)),
                    2,
                    PHP_ROUND_HALF_UP);
            } else {
                # Steuern kommen zzgl. auf den Artikelpreis
                return round(
                    ($amount * (1 + $this->data->taxrate/100)) - $amount,
                    2,
                    PHP_ROUND_HALF_UP);
            }
        }
    }

    private function generateVoucherItems() {
        $voucherItems = [];

        foreach ($this->invoiceitems as $item){

            $info = [
                'amount' => floatval($item->amount),
                'taxAmount' => $this->calculateVoucherItemTaxAmount($item->amount),
                'taxRatePercent' => (WHMCSTaxHelper::isCustomerSmallBusiness()) ? 0 : intval($this->data->taxrate),
                'categoryId' => WHMCSTaxHelper::getCategoryID(),
            ];

            array_push($voucherItems, $info);
        }

        return $voucherItems;
    }

    private function savePDF(){
        $dir = InvoicesHelper::getDirName($this->data->date);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        $fileName = $dir . '/' . $this->invoiceid . '.pdf';
        $file = fopen($fileName, "w+");
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_FILE    => $file,
            CURLOPT_TIMEOUT =>  60,
            CURLOPT_URL     =>  DatabaseHelper::getWHMCSURL() . 'PDFInvoiceLexoffice.php?user=' . LexofficeModuleAccessHelper::getLexofficeKey() . '&pass=' . LexofficeModuleAccessHelper::getEverHypeKey() . '&id=' . $this->invoiceid
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($file);
    }

    public function integrateInvoice()  {
        # we need the pdf later.
        # We first create the customer & trigger the integration with lexoffice.

        localAPI('UpdateClient', [
                "clientid" => $this->data->userid,
                "status" => "Active"
            ]
        );
        sleep(1);


        $this->savePDF();
        $fields = $this->generateLexofficeData();

        # go through all fields
        foreach ($fields['voucherItems'] as $item){
            if ($item['amount'] < 0){
                return false;
            }
        }

        if ($fields['totalGrossAmount'] < 0.01){
            return;
        }

        $integrateCurl = curl_init();

        for($i = 0; $i < 3; $i++){

            if ($i == 1){
                $fields['totalGrossAmount'] -= 0.01;
            } elseif ($i == 2){
                $fields['totalGrossAmount'] += 0.01;
            }

            curl_setopt_array($integrateCurl, array(
                CURLOPT_URL => 'https://api.lexoffice.io/v1/vouchers',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($fields),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . LexofficeModuleAccessHelper::getLexofficeKey(),
                    'Content-Type: application/json'
                ],
            ));

            $voucherResponse = json_decode(curl_exec($integrateCurl));
            $statusCode = curl_getinfo($integrateCurl, CURLINFO_HTTP_CODE);
            # we do not continue
            if ($statusCode != 200 && $statusCode != 201){
                if ($i == 2) {
                    error_log(json_encode($voucherResponse));
                    return;
                }
            } else {
                # everything was ok, going further
                break;
            }
        }

        $voucherFileCurl = curl_init();

        curl_setopt_array($voucherFileCurl, array(
            CURLOPT_URL => 'https://api.lexoffice.io/v1/vouchers/' . $voucherResponse->id . '/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile(
                    InvoicesHelper::getDirName($this->data->date) . '/' . $this->invoiceid . '.pdf'
                )
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . LexofficeModuleAccessHelper::getLexofficeKey(),
            ],
        ));
        # true => to an array
        $voucherFileResponse = json_decode(curl_exec($voucherFileCurl), true);
        $statusCode = curl_getinfo($voucherFileCurl, CURLINFO_HTTP_CODE);
        if ($statusCode != 200 && $statusCode != 201 && $statusCode != 202){
            throw new Exception('Could not upload voucher. Please check.');
        }

        $this->markAsIntegrated($voucherResponse->id);
        return true;
    }

    private function markAsIntegrated($lexoffice_id){
        try {
            Capsule::table('everhype_lexoffice_invoices')->insert(
                [
                    'invoiceid' => $this->invoiceid,
                    'lexoffice_id' => $lexoffice_id,
                    'uploaded_at' => date("Y-m-d H:i:s", time())
                ]
            );
            Capsule::commit();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}

class LexofficeClient {
    private $vars;
    /**
     * @var mixed
     */
    private $userID;

    /**
     * @param $vars
     */
    public function __construct($vars) {
        $this->vars = $vars;
        $this->userID = $this->getUserIDFromVars();
    }

    public function getUserIDFromVars(){
        # we have to search userid now
        if (array_key_exists('userid', $this->vars)){
            return $this->vars['userid'];
        } elseif (array_key_exists('user_id', $this->vars)){
            return $this->vars['user_id'];
        } elseif (array_key_exists('clientid', $this->vars)){
            return $this->vars['clientid'];
        } elseif (array_key_exists('client_id', $this->vars)){
            return $this->vars['client_id'];
        } else {
            throw new Exception('COULD NOT FETCH USER ID');
        }
    }

    public function isClientIntegrated()  {
        return 1 == Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->count();
    }

    public function getVersion(){
        if ($this->isClientIntegrated()){
            return $this->getVersionFromAPI();
        } else {
            return 0;
        }
    }

    private function get($name){
        return $this->vars[$name];
    }

    private function getFields(){
        # Check if customer is company user
        # check if user is business account or not
        # user is business
        $fields = array(
            'version' => $this->getVersion(),
            'roles' => array(
                'customer' => array(
                    'number' => ''
                )
            ),
            'addresses' => array(
                'billing' => array(
                    array(
                        'street' =>  $this->vars['address1'],
                        'zip' => $this->vars['postcode'],
                        'city' => $this->vars['city'],
                        'countryCode' => $this->vars['country'],
                    )
                )
            ),
            'emailAddresses' => array(
                'business' => array(
                    $this->vars['email']
                ),
            ),
            'note' => 'Importiert aus WHMCS. NutzerID - ' . $this->userID
        );



        if (!empty($this->vars['companyname']) && strlen($this->vars['companyname']) != 0){
            # Customer is business owner
            $fields['company'] = array(
                'name' => $this->vars['companyname'],
                'street' =>  $this->vars['address1'],
                'zip' => $this->vars['postcode'],
                'city' => $this->vars['city'],
                'countryCode' => $this->vars['country'],
                'contactPersons' => array(
                    array(
                        'salutation' => 'Herr/Frau',
                        'firstName'=> $this->vars['firstname'],
                        'lastName' => $this->vars['lastname'],
                        'emailAddress' => $this->vars['email'],
                    )
                )
            );
        } else {
            $fields["person"] = array(
                'salutation' => 'Herr/Frau',
                'firstName' => $this->vars['firstname'],
                'lastName' => $this->vars['lastname'],
            );
        }

        return $fields;
    }

    private function getLexofficeID(){
        return Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->first()->lexoffice_id;
    }

    private function createContact() {
        $fields = $this->getFields();
        $createCurl = curl_init();
        curl_setopt_array($createCurl, array(
            CURLOPT_URL => 'https://api.lexoffice.io/v1/contacts',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($fields),

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . LexofficeModuleAccessHelper::getLexofficeKey(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ));
        $result = curl_exec($createCurl);
        $createResponse = json_decode($result);
        $statusCode = curl_getinfo($createCurl, CURLINFO_HTTP_CODE);

        if ($statusCode != 200 && $statusCode != 201 && $statusCode != 202){
            throw new Exception('Could not create user. Error ' . $result);
        }

        # everything is ok
        Capsule::table('everhype_lexoffice_contacts')->insert([
            'userid' => $this->userID,
            'lexoffice_id' => $createResponse->id,
            'version' => $createResponse->version
        ]);
        Capsule::commit();
    }

    private function getVersionFromAPI(){
        $apiVersion = curl_init();
        curl_setopt_array($apiVersion, array(
            CURLOPT_URL => 'https://api.lexoffice.io/v1/contacts/' . $this->getLexofficeID(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . LexofficeModuleAccessHelper::getLexofficeKey(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ));

        $result = json_decode(curl_exec($apiVersion));
        $statusCode = curl_getinfo($apiVersion, CURLINFO_HTTP_CODE);

        if ($statusCode != 200 && $statusCode != 201 && $statusCode != 202){
            throw new Exception(json_encode($result));
        }
        return $result->version;
    }

    private function updateContact() {
        $fields = $this->getFields();
        $updateCurl = curl_init();
        curl_setopt_array($updateCurl, array(
            CURLOPT_URL => 'https://api.lexoffice.io/v1/contacts/' . $this->getLexofficeID(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . LexofficeModuleAccessHelper::getLexofficeKey(),
                'Content-Type: application/json'
            ],
        ));

        $updateResponse = json_decode(curl_exec($updateCurl));
        $statusCode = curl_getinfo($updateCurl, CURLINFO_HTTP_CODE);

        if ($statusCode != 200 && $statusCode != 201 && $statusCode != 202 && $statusCode != 204){
            throw new Exception('Could not update user. Error => ' . json_encode($updateResponse));
        }

        # everything is ok
        Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->update([
            'version' => $fields['version']
        ]);
        Capsule::commit();
    }

    public function integrate() {
        if ($this->isClientIntegrated()){
            $this->updateContact();
        } else {
            $this->createContact();
        }
    }

}

class CronHelper{
    public static function doDailyCron() {
        # get all paid invoices in lexoffice
        $start_from = LexofficeModuleAccessHelper::getStartInvoice();
        if ($start_from == null || strlen($start_from) == 0 || !strtotime($start_from)){
            $start_from = '01.01.2019';
        }

        $checkDATE = strtotime($start_from);
        $invoicesUpload = [];
        $paidInvoices = InvoicesHelper::getPaidInvoices();
        # drop all paid invoices which are already integrated
        foreach ($paidInvoices as $invoice){
            $invoiceModel = new LexofficeInvoice($invoice->id);
            if (!$invoiceModel->isIntegrated() && $invoiceModel->isPaid()){
                try {
                    if (strtotime($invoice->date) > $checkDATE){
                        echo "Rechnung wird nun syncronsiert -> #" . $invoice->id . PHP_EOL;
                        $invoiceModel->integrateInvoice();
                    }
                } catch (Exception $e) {
                    echo PHP_EOL . PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL . PHP_EOL;
                }
            }
        }
    }
}
