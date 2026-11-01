<?php

use Illuminate\Http\Request;

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__browser-test-health') {
    $app = require __DIR__.'/bootstrap.php';
    header('Content-Type: text/plain');
    echo basename(getenv('BROWSER_TEST_DIR'));

    return;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(dirname(__DIR__, 2).'/public'.$uri);
$public = realpath(dirname(__DIR__, 2).'/public');
if ($file && str_starts_with($file, $public.'/') && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
