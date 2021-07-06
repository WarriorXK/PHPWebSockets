#!/usr/bin/env php
<?php

declare(strict_types = 1);

require_once __DIR__ . '/../../vendor/autoload.php';

$cliArgs = [
    'address'          => '',
    'message'          => '',
    'message-interval' => 1,
    'message-count'    => 0,
    'ping-interval'    => 0,
    'die-at'           => 0.0,
    'close-at'         => 0.0,
    'disconnect-at'    => 0.0,
    'async'            => FALSE,
];

foreach ($argv as $item) {

    if (substr($item, 0, 10) === '--address=') {
        $cliArgs['address'] = substr($item, 10);
    } elseif (substr($item, 0, 10) === '--message=') {
        $cliArgs['message'] = substr($item, 10);
    } elseif (substr($item, 0, 19) === '--message-interval=') {
        $cliArgs['message-interval'] = (int) substr($item, 19);
    } elseif (substr($item, 0, 16) === '--message-count=') {
        $cliArgs['message-count'] = (int) substr($item, 16);
    } elseif (substr($item, 0, 16) === '--ping-interval=') {
        $cliArgs['ping-interval'] = (int) substr($item, 16);
    } elseif (substr($item, 0, 9) === '--die-at=') {
        $cliArgs['die-at'] = (float) substr($item, 9);
    } elseif (substr($item, 0, 11) === '--close-at=') {
        $cliArgs['close-at'] = (float) substr($item, 11);
    } elseif (substr($item, 0, 16) === '--disconnect-at=') {
        $cliArgs['disconnect-at'] = (float) substr($item, 16);
    } elseif ($item === '--async') {
        $cliArgs['async'] = TRUE;
    }

}

$client = new \PHPWebSockets\Client();
if (!$client->connect($cliArgs['address'], '/', [], $cliArgs['async'])) {
    throw new \RuntimeException('Unable to connect to ' . $cliArgs['address']);
}

$messageCount = 0;
$lastMessage = 0;
$pingCount = 0;
$lastPing = 0;

while ($client->isOpen()) {

    if ($cliArgs['die-at'] > 0.0 && microtime(TRUE) >= $cliArgs['die-at']) {
        exit();
    }

    if ($cliArgs['disconnect-at'] > 0.0 && microtime(TRUE) >= $cliArgs['disconnect-at']) {
        $client->sendDisconnect(\PHPWebSockets::CLOSECODE_NORMAL);
    }

    if ($cliArgs['close-at'] > 0.0 && microtime(TRUE) >= $cliArgs['close-at']) {
        $client->close();
    }

    foreach ($client->update(0.1) as $update) {
        // Nothing
    }

    if (!$client->hasHandshake()) {
        continue;
    }

    if ($cliArgs['message-count'] > 0 && $messageCount > $cliArgs['message-count']) {
        $client->sendDisconnect(\PHPWebSockets::CLOSECODE_NORMAL);
    }

    if ($client->isDisconnecting()) {
        continue;
    }

    if ($cliArgs['message'] && ($lastMessage + $cliArgs['message-interval']) < time()) {

        $client->write($cliArgs['message']);

        $lastMessage = microtime(TRUE);
        $messageCount++;

    }

    if ($cliArgs['ping-interval'] > 0 && ($lastPing + $cliArgs['ping-interval']) < time()) {

        $client->write('', \PHPWebSockets::OPCODE_PING);

        $lastPing = microtime(TRUE);
        $pingCount++;

    }

}
