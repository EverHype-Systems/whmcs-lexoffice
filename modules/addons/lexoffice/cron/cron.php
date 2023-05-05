<?php

require_once __DIR__ . '/../../../init.php';

# allow only from cli
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

use helpers\CronHelper;

$cron = new CronHelper();

$cron->run();