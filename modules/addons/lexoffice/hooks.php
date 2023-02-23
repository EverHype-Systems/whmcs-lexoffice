<?php

require_once dirname(__FILE__) . '/LexofficeHelper.php';

add_hook('DailyCronJob', 1, function($vars) {
    try {
        echo "lexoffice Export wird gestartet..." . PHP_EOL;
        CronHelper::doDailyCron();
    } catch (Exception $e) {
        echo "Fehler bei der Ausführung: " . $e->getMessage();
    }
});

add_hook('ClientAdd', 1, function($vars) {
    $client = new LexofficeClient($vars);
    $client->integrate();
});

add_hook('ClientEdit', 1, function($vars) {
    $client = new LexofficeClient($vars);
    $client->integrate();
});