<?php

require_once 'vendor/autoload.php';
require_once('../wp-load.php');
use CodexAgency\WooImporter\Woo;

$path = __DIR__.'/csv/';
$wpLoad = __DIR__.'../wp-load.php';
$woo = new Woo();
$woo->setCsvPath($path);
$woo->run();
