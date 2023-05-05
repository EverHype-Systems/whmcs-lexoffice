<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

use Illuminate\Database\Capsule\Manager as Capsule;

function lexoffice_config(): array
{
    return [
        "name" => "lexoffice Export",
        "description" => "Dieses Modul exportiert alle bezahlten Rechnungen in Lexoffice. Bitte beachten Sie, dass die Zuordnungen der Zahlungen manuell in Lexoffice vorgenommen werden muss (GoBD).",
        "version" => 1.2,
        "author" => "EverHype Systems GmbH",
        "language" => "german",
        "fields" => [
            "everhype_lexoffice_key" => [
                "FriendlyName" => "lexoffice Public API - Key",
                "Type" => "text",
                "Size" => "50",
                "Description" => "<br>Dieser Schlüssel dient der Authentifizierung innerhalb der Public API von lexoffice.
                <br>Die Lebensdauer des API Keys beträgt 24 Monate.<br>Sie können den Key bei Bedarf in der API Keys Verwaltung (https://app.lexoffice.de/addons/public-api) ersetzen.",
                "Default" => "",
            ],
            "everhype_license_key" => [
                "FriendlyName" => "Secret-Key",
                "Type" => "text",
                "Size" => "50",
                "Description" => "<br>Bitte setzen Sie hier einen SECRET-KEY ein. Dieser dient der Nutzung des Moduls und sichert einige Endpunkte.",
            ],
            "everhype_start_invoice" => [
                "FriendlyName" => "Start - Rechnungsdatum",
                "Type" => "text",
                "Size" => "50",
                "Description" => "<br>Bitte beachten Sie, dass die Eingabe dieser Datums bedeutet, dass <i>nur</i> Rechnungen, die ein größeres Datum haben exportiert werden.<br>Alle Rechnungen, die drunter liegen, werden ignoriert.",
                "Default" => "01.01.2019",
            ],
        ],
    ];
}

function lexoffice_activate(): array
{
    try {
        if (!Capsule::schema()->hasTable('everhype_lexoffice_invoices')) {
            Capsule::schema()->create('everhype_lexoffice_invoices', function ($table) {
                $table->increments('id')->unique();
                $table->integer('invoiceid')->unique();
                $table->string('lexoffice_id')->unique();
                $table->datetime('uploaded_at')->default('0000-00-00 00:00:00');
            });
        }
        if (!Capsule::schema()->hasTable('everhype_lexoffice_contacts')) {
            Capsule::schema()->create('everhype_lexoffice_contacts', function ($table) {
                $table->increments('id')->unique();
                $table->integer('userid')->unique();
                $table->string('lexoffice_id')->unique();
                $table->integer('version');
            });
        }
        return [
            'status' => "success",
            'description' => 'lexoffice Export erfolgreich initalisiert.',
        ];
    } catch (Exception $e) {
        return [
            'status' => "error",
            'description' => 'Unable to create lexoffice databases: ' . $e->getMessage(),
        ];
    }
}
