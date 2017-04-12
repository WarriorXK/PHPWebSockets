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

\PHPWebSocket::Log(LOG_INFO, 'Starting test' . PHP_EOL);

$websocket = new \PHPWebSocket\Server('tcp://0.0.0.0:9001');
$bufferType = $argv[2] ?? NULL;

$descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
$wstestProc = proc_open('wstest -m fuzzingclient -s Autobahn/fuzzingclient.json', $descriptorSpec, $pipes, __DIR__);

try {

    while (proc_get_status($wstestProc)['running'] ?? FALSE) {

        $updates = $websocket->update(0.1);
        foreach ($updates as $update) {

            if ($update instanceof Read) {

                $sourceObj = $update->getSourceObject();
                $opcode = $update->getCode();
                switch ($opcode) {
                    case Read::C_NEWCONNECTION:

                        $sourceObj->accept();

                        switch ($bufferType) {
                            case 'memory':
                                // Default, do nothing
                                break;
                            case 'tmpfile':

                                $sourceObj->setNewMessageStreamCallback(function (array $headers) {
                                    return tmpfile();
                                });

                                break;
                            default:
                                throw new \Exception('Unknown buffer type specified: ' . ($bufferType === NULL ? 'NULL' : $bufferType));
                        }

                        break;
                    case Read::C_READ:

                        $opcode = $update->getOpcode();
                        switch ($opcode) {
                            case \PHPWebSocket::OPCODE_CONTINUE:
                            case \PHPWebSocket::OPCODE_FRAME_TEXT:
                            case \PHPWebSocket::OPCODE_FRAME_BINARY:

                                if ($sourceObj->isDisconnecting()) {
                                    break;
                                }

                                $message = $update->getMessage() ?? '';
                                if ($message === '') {

                                    $stream = $update->getStream();
                                    if ($stream) {

                                        rewind($stream);
                                        $message = stream_get_contents($stream);

                                    }

                                }

                                if ($message !== NULL) {
                                    $sourceObj->write($message, $opcode);
                                }

                                break;
                        }

                        break;
                }

            }

        }

    }

    \PHPWebSocket::Log(LOG_INFO, 'Test ended, closing websocket');

    $websocket->close();

    \PHPWebSocket::Log(LOG_INFO, 'Getting results..');

    $outputFile = '/tmp/reports/index.json';
    if (!file_exists($outputFile)) {
        \PHPWebSocket::Log(LOG_ERR, 'File "' . $outputFile . '" doesn\'t exist!');
        exit(1);
    }

    $hasFailures = FALSE;
    $testCases = json_decode(file_get_contents($outputFile), TRUE)[$websocket->getServerIdentifier()] ?? NULL;
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
