<?php

namespace helpers;

require_once __DIR__ . '/../../../init.php';
use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;
class WHMCSTaxHelper {


    public function __construct(\lexoffice_client $lexofficeClient)
    {
        $this->lexofficeClient = $lexofficeClient;

        # variable storing if the company is a small business or not
        $this->smallBusiness = $this->lexofficeClient->is_tax_free_company();
        $this->taxType = $this->getTaxType();
    }

    public function getCategoryId(float $taxrate, string $country_code, int $date, bool $euopean_vatid, bool $b2b_business, bool $physical_good = false) {
        return $this->lexofficeClient->get_needed_voucher_booking_id($taxrate, $country_code, $date, $euopean_vatid, $b2b_business, $physical_good);
    }
    public function getTaxType(): string
    {
        if ($this->smallBusiness) {
            return 'gross';
        }
        # get tax type fype from whmcs and determine if the prices in whmcs are net or gross
        return $this->isTaxIncluded() ? 'gross' : 'net';
    }

    public function isTaxIncluded(): bool
    {
        return 'Inclusive' == Capsule::table('tblconfiguration')->where('setting', 'TaxType')->first()->value;
    }
}