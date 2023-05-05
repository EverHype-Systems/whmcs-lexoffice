<?php

namespace helpers;

# require whmcs init file
require_once __DIR__ . '/../../../init.php';

use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;

class InvoicesHelper
{
    public static function getLexofficeContactIdByUserId($userid): string
    {
        return Capsule::table('everhype_lexoffice_contacts')
            ->where('userid', $userid)
            ->first()
            ->lexoffice_id;
    }

    public function getDirName($date): string
    {
        $dt = strtotime($date);
        return __DIR__ . '/invoices/' . date('Y', $dt) . '/' . date('m');
    }

    public function getUnintegratedInvoices(): array
    {
        $unintegrated = [];
        try {
            $paidInvoices = $this->getInvoicesByStatus('Paid');
        } catch (Exception $e) {
            error_log("Error while getting paid invoices: " . $e->getMessage());
            return $unintegrated;
        }

        foreach ($paidInvoices as $invoice) {
            if (!self::isInvoiceIntegrated($invoice->id)) {
                array_push($unintegrated, $invoice);
            }
        }
        return $unintegrated;
    }

    private function getInvoicesByStatus($status): array
    {
        # Check if $status is valid in our enum
        if (!in_array($status, ['Paid', 'Unpaid', 'Cancelled', 'Refunded', 'Collections', 'Draft', 'Pending', 'Overdue'])) {
            throw new Exception('Invalid status');
        }

        return Capsule::table('tblinvoices')->where('status', $status)->get();
    }

    public function isInvoiceIntegrated($invoiceid): bool
    {
        return 1 == Capsule::table('everhype_lexoffice_invoices')->where('invoiceid', $invoiceid)->count();
    }

    public function getInvoiceItems($invoiceid): array
    {
        return Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceid)->get();
    }

    public function getInvoiceNumber($invoiceid)
    {
        return Capsule::table('tblinvoices')->where('id', $invoiceid)->first()->invoicenum;
    }

    public function getAllInvoicesStartingByDateAndStatus($date, $status, $notIntegrated = true): array
    {
        $invoices = $this->getInvoicesByStatus($status);

        $invoices = array_filter($invoices, function ($invoice) use ($date) {
            return strtotime($invoice->date) >= strtotime($date);
        });

        # filter invoices that are already integrated
        if ($notIntegrated) {
            $invoices = array_filter($invoices, function ($invoice) {
                return !$this->isInvoiceIntegrated($invoice->id);
            });
        }

        return $invoices;
    }

    public function getInvoice(int $invoiceid)
    {
        return Capsule::table('tblinvoices')->where('id', $invoiceid)->first();
    }
}
