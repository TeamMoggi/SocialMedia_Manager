<?php

// Tests get their own throw-away data folder, so they never touch Data or DevData.
require __DIR__.'/../vendor/autoload.php';

$dataDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-test-data';
foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'media'] as $sub) {
    $path = $dataDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}
putenv('SMM_DATA_DIR='.$dataDir);
$_ENV['SMM_DATA_DIR'] = $_SERVER['SMM_DATA_DIR'] = $dataDir;
