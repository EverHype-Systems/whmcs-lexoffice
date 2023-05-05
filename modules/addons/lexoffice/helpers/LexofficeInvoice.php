<?php

namespace helpers;
require_once __DIR__ . '/../../../init.php';

use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;

class LexofficeInvoice
{

    public function __construct(int $invoiceid, InvoicesHelper $invoiceHelper, \lexoffice_client $lexofficeClient, WHMCSTaxHelper $whmcsTaxHelper, LexofficeClient $userLexofficeClient, LexofficeModuleHelper $lexofficeModuleHelper)
    {
        $this->invoiceHelper = $invoiceHelper;
        $this->lexofficeClient = $lexofficeClient;
        $this->whmcsTaxHelper = $whmcsTaxHelper;
        $this->userLexofficeClient = $userLexofficeClient;
        $this->lexofficeModuleHelper = $lexofficeModuleHelper;
        $this->invoiceid = $invoiceid;

        # get invoice data from whmcs
        $this->data = $this->invoiceHelper->getInvoice($invoiceid);

        # get invoice items from whmcs
        $this->items = $this->invoiceHelper->getInvoiceItems($invoiceid);
    }

    public function isPaid(): bool
    {
        return $this->data->status == 'Paid';
    }

    public function isIntegrated(): bool
    {
        return $this->invoiceHelper->isInvoiceIntegrated($this->invoiceid);
    }

    public function integrateInvoice()
    {
        # integrate the customer first
        $this->userLexofficeClient->integrate();

        # saving the pdf for the invoice
        $this->savePDF();

        # generate the lexoffice data
        $fields = $this->generateLexofficeData();

        /**
         * if all items are 0.00, we don't need to integrate the invoice
         */
        if ($fields['totalGrossAmount'] < 0.01) return;


        $integrateCurl = curl_init();

        for ($i = 0; $i < 3; $i++) {

            if ($i == 1) {
                $fields['totalGrossAmount'] -= 0.01;
            } elseif ($i == 2) {
                $fields['totalGrossAmount'] += 0.01;
            }

            try {
                $voucherResponse = $this->lexofficeClient->create_voucher($fields);
                break;
            } catch (Exception $e) {
                error_log("Error while integrating invoice:, retrying " . $e->getMessage());
                continue;
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
                    $this->invoiceHelper->getDirName($this->data->date) . '/' . $this->invoiceid . '.pdf'
                )
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->lexofficeModuleHelper->getLexofficeKey(),
            ],
        ));
        # true => to an array
        $voucherFileResponse = json_decode(curl_exec($voucherFileCurl), true);
        $statusCode = curl_getinfo($voucherFileCurl, CURLINFO_HTTP_CODE);
        if ($statusCode != 200 && $statusCode != 201 && $statusCode != 202) {
            throw new Exception('Could not upload voucher. Please check.');
        }

        $this->markAsIntegrated($voucherResponse->id);
        return true;
    }

    private function savePDF()
    {
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
            CURLOPT_FILE => $file,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_URL => $this->lexofficeModuleHelper->getSystemUrl() . 'PDFInvoiceLexoffice.php?user=' . $this->lexofficeModuleHelper->fetchLexofficeKey() . '&pass=' . $this->lexofficeModuleHelper->fetchSecret() . '&id=' . $this->invoiceid
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($file);
    }

    private function generateLexofficeData(): array
    {
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
            'taxType' => $this->whmcsTaxHelper->getTaxType(),
            'totalGrossAmount' => $this->calculateVoucherTotalGrossAmount($voucherItems),
            'totalTaxAmount' => $this->calculateVoucherTotalTaxAmount($voucherItems),
            'contactId' => $this->userLexofficeClient->getLexofficeID()
        ];
    }

    private function generateVoucherItems(): array
    {
        $voucherItems = [];

        foreach ($this->items as $item) {


            try {
                $info = [
                    'amount' => floatval($item->amount),
                    'taxAmount' => $this->calculateVoucherItemTaxAmount($item->amount),
                    'taxRatePercent' => ($this->whmcsTaxHelper->smallBusiness) ? 0 : intval($this->data->taxrate),
                    'categoryId' => $this->whmcsTaxHelper->getCategoryId(
                        floatval($this->data->taxrate),
                        $this->userLexofficeClient->getVarByKey('country'),
                        strtotime($this->data->date),
                        $this->userLexofficeClient->isEuropean() && $this->userLexofficeClient->isBusiness(),
                        $this->userLexofficeClient->isBusiness()
                    ),
                ];
            } catch (\lexoffice_exception $e) {
                error_log("Error while generating voucher items: " . $e->getMessage());
                continue;
            }

            # append the item to the voucherItems array
            $voucherItems[] = $info;
        }

        return $voucherItems;
    }

    private function calculateVoucherItemTaxAmount(float $amount): float
    {
        if ($this->whmcsTaxHelper->smallBusiness) {
            # Kleinunternehmer => keine Steuer
            return floatval(0);
        } else {
            if ($this->whmcsTaxHelper->isTaxIncluded()) {
                # Steuer ist im Preis inbegriffen.
                return round(
                    $amount - ($amount / (1 + $this->data->taxrate / 100)),
                    2,
                    PHP_ROUND_HALF_UP);
            } else {
                # Steuern kommen zzgl. auf den Artikelpreis
                return round(
                    ($amount * (1 + $this->data->taxrate / 100)) - $amount,
                    2,
                    PHP_ROUND_HALF_UP);
            }
        }
    }

    private function calculateVoucherTotalGrossAmount($voucherItems): float
    {
        $total = floatval(0);
        $total_tax = floatval(0);
        foreach ($voucherItems as $item) {
            $total += $item['amount'];
            $total_tax += $item['taxAmount'];
        }
        if ($this->whmcsTaxHelper->isTaxIncluded() or $this->whmcsTaxHelper->smallBusiness) {
            return round($total, 2, PHP_ROUND_HALF_UP);
        } else {
            return round($total + $total_tax, 2, PHP_ROUND_HALF_UP);
        }
    }

    private function calculateVoucherTotalTaxAmount($voucherItems): float
    {
        $total_tax = floatval(0);
        foreach ($voucherItems as $item) {
            $total_tax += $item['taxAmount'];
        }
        return $total_tax;
    }

    private function markAsIntegrated($lexoffice_id)
    {
        Capsule::beginTransaction();

        try {
            Capsule::table('everhype_lexoffice_invoices')->insert([
                'invoiceid' => $this->invoiceid,
                'lexoffice_id' => $lexoffice_id,
                'uploaded_at' => date("Y-m-d H:i:s")
            ]);

            Capsule::commit();
        } catch (Exception $e) {
            Capsule::rollBack();
            throw new Exception($e->getMessage());
        }
    }

}