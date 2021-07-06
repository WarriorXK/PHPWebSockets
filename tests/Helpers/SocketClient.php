#!/usr/bin/env php
<?php

declare(strict_types = 1);

require_once __DIR__ . '/../../vendor/autoload.php';

$cliArgs = [
    'address'          => '',
    'die-at'           => 0.0,
    'close-at'         => 0.0,
    'async'            => FALSE,
    'connect-timeout'  => 0.0,
];

foreach ($argv as $item) {

    if (substr($item, 0, 10) === '--address=') {
        $cliArgs['address'] = substr($item, 10);
    } elseif (substr($item, 0, 9) === '--die-at=') {
        $cliArgs['die-at'] = (float) substr($item, 9);
    } elseif (substr($item, 0, 11) === '--close-at=') {
        $cliArgs['close-at'] = (float) substr($item, 11);
    } elseif (substr($item, 0, 18) === '--connect-timeout=') {
        $cliArgs['connect-timeout'] = (float) substr($item, 11);
    } elseif ($item === '--async') {
        $cliArgs['async'] = TRUE;
    }

}

$flags = ($cliArgs['async'] ? STREAM_CLIENT_ASYNC_CONNECT : STREAM_CLIENT_CONNECT);

$stream = @stream_socket_client($cliArgs['address'], $errorCode, $errorMessage, $cliArgs['connect-timeout'], $flags);
if ($stream === FALSE) {
    echo 'Connect failed' . PHP_EOL;
    exit(1);
}

while (TRUE) {

    $now = microtime(TRUE);
    if ($now >= $cliArgs['die-at']) {
        exit();
    }
    if ($now >= $cliArgs['close-at']) {
        fclose($stream);
        exit();
    }

    $read = [$stream];
    $write = [$stream];
    $except = [$stream];

    if (stream_select($read, $write, $except, 1)) {

        foreach ($read as $socket) {

            do {
                $data = fread($socket, 8192);
            } while (strlen($data) > 0);

        }

    }

}
