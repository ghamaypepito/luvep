<?php

// TLS is terminated by the loopback-only browser-test proxy.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '8443';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(__DIR__.'/../../public');
$file = realpath($public.$path);
if ($path !== '/' && $file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $public.'/index.php';
