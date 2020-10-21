<?php

declare(strict_types = 1);

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2020 Kevin Meijer
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
use PHPWebSockets\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class ClientTest extends TestCase {

    protected const ADDRESS = 'tcp://127.0.0.1:9001';
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

        $this->assertContains($this->_bufferType, static::VALID_BUFFER_TYPES);

        \PHPWebSockets::Log(LogLevel::INFO, 'Using buffer type ' . $this->_bufferType);

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $this->_autobahnProcess = proc_open('wstest -m fuzzingserver -s Resources/Autobahn/fuzzingserver.json', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        sleep(2);

        $client = $this->_createClient();
        $connectResult = $client->connect(static::ADDRESS, '/getCaseCount');

        $this->assertTrue($connectResult, 'Unable to connect to server: ' . $client->getLastError());

        while ($client->isOpen()) {

            foreach ($client->update(NULL) as $key => $value) {

                \PHPWebSockets::Log(LogLevel::INFO, $value . '');

                if ($value instanceof Read && $value->getCode() === Read::C_READ) {

                    $msg = $value->getMessage() ?? NULL;
                    if ($msg === NULL) {

                        $stream = $value->getStream();
                        rewind($stream);
                        $msg = stream_get_contents($stream);

                    }

                    $this->_caseCount = (int) $msg;

                }

            }

        }

        $this->assertGreaterThan(0, $this->_caseCount, 'Unable to get case count from autobahn server!');

        \PHPWebSockets::Log(LogLevel::INFO, 'Will run ' . $this->_caseCount . ' test cases');

    }

    protected function tearDown() : void {

        \PHPWebSockets::Log(LogLevel::INFO, 'Tearing down');
        proc_terminate($this->_autobahnProcess);

    }

    public function testClient() : void {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting tests..');

        for ($i = 0; $i < $this->_caseCount; $i++) {

            $client = $this->_createClient();
            $client->connect(static::ADDRESS, '/runCase?case=' . ($i + 1) . '&agent=' . $client->getUserAgent());

            while ($client->isOpen()) {

                $updates = $client->update(NULL);
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

        \PHPWebSockets::Log(LogLevel::INFO, 'All test cases ran, asking for report update');
        $client = $this->_createClient();
        $client->connect(static::ADDRESS, '/updateReports?agent=' . $client->getUserAgent());

        while ($client->isOpen()) {
            foreach ($client->update(NULL) as $key => $value) {
                // Nothing, the remote will close it for us
            }
        }

        \PHPWebSockets::Log(LogLevel::INFO, 'Reports finished, getting results..');
        $outputFile = '/tmp/reports/index.json';
        $this->assertFileExists($outputFile);

        $testCases = json_decode(file_get_contents($outputFile), TRUE)[$client->getUserAgent()] ?? NULL;
        $this->assertNotNull('Unable to get test case results');

        $hasFailures = FALSE;
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

    protected function _createClient() : Client {

        $client = new Client();

        if ($this->_bufferType === 'tmpfile') {

            $client->setNewMessageStreamCallback(function (array $headers) {
                return tmpfile();
            });

        }

        return $client;
    }
}
