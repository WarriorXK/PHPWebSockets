#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2021 Kevin Meijer
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * - - - - - - - - - - - - - - END LICENSE BLOCK - - - - - - - - - - - - -
 */

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
