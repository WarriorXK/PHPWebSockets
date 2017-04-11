<?php

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 Kevin Meijer
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

require_once(__DIR__ . '/../PHPWebSocket.php.inc');

use \PHPWebSocket\Update\Read;

$address = 'tcp://127.0.0.1:9001';
$port = 9001;

$descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
$wstestProc = proc_open('wstest -m fuzzingserver -s Autobahn/fuzzingserver.json', $descriptorSpec, $pipes, __DIR__);

sleep(2);

try {

    $client = new \PHPWebSocket\Client();

    $bufferType = $argv[2] ?? NULL;
    switch ($bufferType) {
        case 'memory':
            // Default, do nothing
            break;
        case 'tmpfile':

            $client->setNewMessageStreamCallback(function (array $headers) {
                return tmpfile();
            });

            break;
        default:
            throw new \Exception('Unknown buffer type specified: ' . ($bufferType === NULL ? 'NULL' : $bufferType));
    }

    if (!$client->connect($address, '/getCaseCount')) {
        \PHPWebSocket::Log(LOG_ERR, 'Unable to connect to server: ' . $client->getLastError());
        exit(1);
    }

    $caseCount = NULL;

    while ($client->isOpen()) {
        foreach ($client->update() as $key => $value) {

            \PHPWebSocket::Log(LOG_INFO, $value . '');

            if ($value instanceof Read && $value->getCode() === Read::C_READ) {

                $msg = $value->getMessage() ?? NULL;
                if ($msg === NULL) {
                    $msg = stream_get_contents($value->getStream());
                }

                $caseCount = (int) $msg;

            }
        }
    }

    if ($caseCount === 0) {
        throw new \Exception('Unable to get case count from autobahn server!');
    }

    \PHPWebSocket::Log(LOG_INFO, 'Will run ' . $caseCount . ' test cases');

    for ($i = 0; $i < $caseCount; $i++) {

        $client = new \PHPWebSocket\Client();
        $client->connect($address, '/runCase?case=' . ($i + 1) . '&agent=' . $client->getUserAgent());

        while ($client->isOpen()) {

            $updates = $client->update();
            foreach ($updates as $update) {

                if ($update instanceof Read && $update->getCode() === Read::C_READ) {

                    $message = $update->getMessage() ?? '';
                    if ($message === '') {

                        $stream = $update->getStream();
                        if ($stream) {

                            rewind($stream);
                            $message = stream_get_contents($stream);

                        }

                    }

                    $client->write($message, $update->getOpcode());

                }

            }

        }

    }

    \PHPWebSocket::Log(LOG_INFO, 'All test cases ran, asking for report update');

    $client = new \PHPWebSocket\Client();
    $client->connect($address, '/updateReports?agent=' . $client->getUserAgent());

    while ($client->isOpen()) {
        foreach ($client->update() as $key => $value) {

        }
    }

    \PHPWebSocket::Log(LOG_INFO, 'Reports finished, getting results..');

    $outputFile = '/tmp/reports/index.json';
    if (!file_exists($outputFile)) {
        \PHPWebSocket::Log(LOG_ALERT, 'File "' . $outputFile . '" doesn\'t exist!');
        exit(1);
    }

    $hasFailures = FALSE;
    $testCases = json_decode(file_get_contents($outputFile), TRUE)[$client->getUserAgent()] ?? NULL;
    if ($testCases === NULL) {
        \PHPWebSocket::Log(LOG_ERR, 'Unable to get test case results!');
    } else {

        foreach ($testCases as $case => $data) {

            \PHPWebSocket::Log(LOG_INFO, $case . ' => ' . $data['behavior']);

            switch ($data['behavior']) {
                case 'OK':
                case 'NON-STRICT':
                case 'INFORMATIONAL':
                case 'UNIMPLEMENTED':
                    break;
                default:
                    $hasFailures = TRUE;
                    break;
            }

        }

    }

} finally {

    proc_terminate($wstestProc);

}

\PHPWebSocket::Log(LOG_INFO, 'Exiting');

exit((int) $hasFailures);
