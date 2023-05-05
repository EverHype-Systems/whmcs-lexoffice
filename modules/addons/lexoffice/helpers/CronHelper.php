<?php

namespace helpers;

use Exception;

class CronHelper
{
    public static function run()
    {
        $lexofficeModuleHelper = new LexofficeModuleHelper();
        $invoiceHelper = new InvoicesHelper();
        $whmcsTaxHelper = new WHMCSTaxHelper(
        # injecting the lexoffice client
            $lexofficeModuleHelper->getLexofficeClient()
        );

        # get all paid & not integrated invoices in lexoffice
        $invoices = $invoiceHelper->getAllInvoicesStartingByDateAndStatus(
            $lexofficeModuleHelper->getInvoiceStartDate(), 'Paid'
        );

        # iterate over all invoices
        foreach ($invoices as $invoice) {
            # create an instance of Lexoffice Client for the user
            $userLexofficeClient = new LexofficeClient(['clientid' => $invoice->userid], $lexofficeModuleHelper->getLexofficeClient());

            # integrate client
            $userLexofficeClient->integrate();

            # create an instance of Lexoffice Invoice
            $lexofficeInvoice = new LexofficeInvoice(
                $invoice->id,
                $invoiceHelper,
                $lexofficeModuleHelper->getLexofficeClient(),
                $whmcsTaxHelper, $userLexofficeClient,
                $lexofficeModuleHelper
            );

            try {
                $lexofficeInvoice->integrateInvoice();
            } catch (Exception $e) {
                echo PHP_EOL . PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL . PHP_EOL;
            }
        }
    }
}