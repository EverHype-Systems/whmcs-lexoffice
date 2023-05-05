<?php

use helpers\CronHelper;
use helpers\LexofficeClient;
use helpers\LexofficeModuleHelper;

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        echo "lexoffice Export wird gestartet..." . PHP_EOL;
        $cron = new CronHelper();
        $cron->run();
    } catch (Exception $e) {
        echo "Fehler bei der Ausführung: " . $e->getMessage();
    }
});

add_hook('ClientAdd', 1, function ($vars) {
    # create an instance of LexofficeModuleHelper
    $lexofficeModuleHelper = new LexofficeModuleHelper();

    # create an instance of Lexoffice Client for the user
    $client = new LexofficeClient($vars, $lexofficeModuleHelper->getLexofficeClient());

    $client->integrate();
});

add_hook('ClientEdit', 1, function ($vars) {
    # create an instance of LexofficeModuleHelper
    $lexofficeModuleHelper = new LexofficeModuleHelper();

    # create an instance of Lexoffice Client for the user
    $client = new LexofficeClient($vars, $lexofficeModuleHelper->getLexofficeClient());

    $client->integrate();
});