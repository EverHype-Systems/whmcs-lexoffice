<?php

namespace helpers;

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../lib/lexoffice-client.php';

use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;

class LexofficeModuleHelper
{
    public function __construct()
    {
        $this->systemUrl = $this->fetchURL();
        $this->lexofficeKey = $this->fetchLexofficeKey();
        $this->lexofficeClient = new \lexoffice_client(["api_key" => $this->lexofficeKey]);
    }

    private function fetchURL(): string
    {
        return rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first()->value,
                '/') . '/';
    }

    public function fetchLexofficeKey(): string
    {
        return Capsule::table('tbladdonmodules')->where('module', 'lexoffice')->where('setting',
            'everhype_lexoffice_key')->first()->value;
    }

    public function getLexofficeClient(): \lexoffice_client
    {
        return $this->lexofficeClient;
    }

    public function getInvoiceStartDate(): string
    {
        return Capsule::table('tbladdonmodules')->where('module', 'lexoffice')->where('setting',
            'everhype_start_invoice')->first()->value;
    }

    public function getSystemUrl(): string
    {
        return $this->systemUrl;
    }

    public function getLexofficeKey(): string
    {
        return $this->lexofficeKey;
    }

    public function fetchSecret(): string
    {
        return Capsule::table('tbladdonmodules')->where('module', 'lexoffice')->where('setting',
            'everhype_lexoffice_secret')->first()->value;
    }


}