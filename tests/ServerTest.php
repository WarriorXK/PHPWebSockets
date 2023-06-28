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

use PHPWebSockets\Update\Read;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class ServerTest extends TestCase {

    protected const ADDRESS = 'tcp://0.0.0.0:9001';
    protected const CONTAINER_NAME = 'fuzzingclient';
    protected const VALID_BUFFER_TYPES = [
        'memory',
        'tmpfile',
    ];

    /**
     * The proc_open resource that represents the fuzzing server
     *
     * @var resource|null
     */
    protected $_autobahnProcess = NULL;

    /**
     * The buffer type to use during this test
     *
     * @var string|null
     */
    protected $_bufferType = NULL;

    /**
     * The output directory for reports
     *
     * @var string
     */
    protected $_reportsDir;

    /**
     * The websocket server
     *
     * @var \PHPWebSockets\Server|null
     */
    protected $_wsServer = NULL;

    /**
     * The amount of cases to run
     *
     * @var int|null
     */
    protected $_caseCount = NULL;

    protected function setUp() : void {

        global $argv;

        foreach ($argv as $arg) {

            if (substr($arg, 0, 11) !== 'buffertype=') {
                continue;
            }

            $this->_bufferType = substr($arg, 11);

        }

        if ($this->_bufferType === NULL) {
            $this->_bufferType = getenv('BUFFERTYPE') ?: NULL;
        }

        $this->assertContains($this->_bufferType, static::VALID_BUFFER_TYPES, 'Invalid buffer type, env: ' . implode(', ', getenv()));

        \PHPWebSockets::Log(LogLevel::INFO, 'Using buffer type ' . $this->_bufferType);

        $this->_wsServer = new \PHPWebSockets\Server(self::ADDRESS);
        $this->_reportsDir = sys_get_temp_dir() . '/ws_reports';
        if (!is_dir($this->_reportsDir)) {
            mkdir($this->_reportsDir);
        }

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $image = 'crossbario/autobahn-testsuite';
        $cmd = 'docker run --rm \
            -v "' . realpath(__DIR__ . '/../Resources/Autobahn') . ':/config" \
            -v "' . $this->_reportsDir . ':/reports" \
            --add-host host.docker.internal:host-gateway \
            --name ' . escapeshellarg(static::CONTAINER_NAME) . ' \
            ' . $image . ' \
            wstest -m fuzzingclient -s /config/fuzzingclient.json
            ';

        \PHPWebSockets::Log(LogLevel::INFO, 'Pulling image ' . $image);
        passthru('docker pull ' . $image);

        $this->_autobahnProcess = proc_open($cmd, $descriptorSpec, $pipes);

    }

    protected function tearDown() : void {

        \PHPWebSockets::Log(LogLevel::INFO, 'Tearing down');
        proc_terminate($this->_autobahnProcess);
        exec('docker container stop ' . escapeshellarg(self::CONTAINER_NAME));

    }

    public function testServer() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..');

        while (proc_get_status($this->_autobahnProcess)['running'] ?? FALSE) {

            $updates = $this->_wsServer->update(0.1);
            foreach ($updates as $update) {

                if ($update instanceof Read) {

                    $sourceConn = $update->getSourceConnection();
                    $opcode = $update->getCode();
                    switch ($opcode) {
                        case Read::C_NEW_CONNECTION:

                            $sourceConn->accept();

                            if ($this->_bufferType === 'tmpfile') {

                                $sourceConn->setNewMessageStreamCallback(function (array $headers) {
                                    return tmpfile();
                                });

                            }

                            break;
                        case Read::C_READ:

                            $opcode = $update->getOpcode();
                            switch ($opcode) {
                                case \PHPWebSockets::OPCODE_CONTINUE:
                                case \PHPWebSockets::OPCODE_FRAME_TEXT:
                                case \PHPWebSockets::OPCODE_FRAME_BINARY:

                                    if ($sourceConn->isDisconnecting()) {
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
                                        $sourceConn->write($message, $opcode);
                                    }

                                    break;
                            }

                            break;
                    }

                }

            }

        }

        \PHPWebSockets::Log(LogLevel::INFO, 'Test ended, closing websocket');

        $this->_wsServer->close();

        \PHPWebSockets::Log(LogLevel::INFO, 'Getting results..');

        $outputFile = $this->_reportsDir . '/index.json';
        $this->assertFileExists($outputFile);

        $hasFailures = FALSE;
        $testCases = json_decode(file_get_contents($outputFile), TRUE)[$this->_wsServer->getServerIdentifier()] ?? NULL;
        $this->assertNotNull($testCases, 'Unable to get test case results');

        foreach ($testCases as $case => $data) {

            \PHPWebSockets::Log(LogLevel::INFO, $case . ' => ' . $data['behavior']);

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

        $this->assertFalse($hasFailures, 'One or more test cases failed!');

        \PHPWebSockets::Log(LogLevel::INFO, 'Test success');

    }
}
