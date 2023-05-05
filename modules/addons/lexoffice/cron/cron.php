<?php

require_once __DIR__ . '/../LexofficeHelper.php';

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

