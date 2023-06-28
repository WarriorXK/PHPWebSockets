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
use PHPWebSockets\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class ClientTest extends TestCase {

    protected const CONTAINER_NAME = 'fuzzingserver';
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
     * The URI to connect to
     *
     * @var string
     */
    protected $_serverURI;

    /**
     * The output directory for reports
     *
     * @var string
     */
    protected $_reportsDir;

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

        $this->_reportsDir = sys_get_temp_dir() . '/ws_reports';
        if (!is_dir($this->_reportsDir)) {
            mkdir($this->_reportsDir);
        }

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $serverPort = 9001;
        $image = 'crossbario/autobahn-testsuite';
        $cmd = 'docker run --rm \
            -v "' . realpath(__DIR__ . '/../Resources/Autobahn') . ':/config" \
            -v "' . $this->_reportsDir . ':/reports" \
            -p ' . $serverPort . ':9001 \
            --name ' . escapeshellarg(self::CONTAINER_NAME) . ' \
            ' . $image . ' \
            wstest -m fuzzingserver -s /config/fuzzingserver.json
            ';

        \PHPWebSockets::Log(LogLevel::INFO, 'Pulling image ' . $image);
        passthru('docker pull ' . $image);

        $this->_autobahnProcess = proc_open($cmd, $descriptorSpec, $pipes);

        $sleepSec = 5;

        \PHPWebSockets::Log(LogLevel::INFO, 'Sleeping ' . $sleepSec . ' seconds to wait for the fuzzing server to start');

        sleep($sleepSec);

        $serverIP = trim(exec('docker inspect -f "{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}" ' . self::CONTAINER_NAME));
        $this->_serverURI = 'tcp://' . $serverIP . ':' . $serverPort;

        $client = $this->_createClient();
        $connectResult = $client->connect($this->_serverURI, '/getCaseCount');

        $this->assertTrue($connectResult, 'Unable to connect to address ' . $this->_serverURI . ': ' . $client->getLastError());

        while ($client->isOpen()) {

            foreach ($client->update(NULL) as $key => $value) {

                \PHPWebSockets::Log(LogLevel::DEBUG, 'Got message: ' . $value);

                if ($value instanceof Read && $value->getCode() === Read::C_READ) {

                    $msg = $value->getMessage() ?? NULL;
                    if ($msg === NULL) {

                        $stream = $value->getStream();
                        rewind($stream);
                        $msg = stream_get_contents($stream);

                    }

                    $this->_caseCount = json_decode($msg);

                }

            }

        }

        $this->assertGreaterThan(0, $this->_caseCount, 'Unable to get case count from autobahn server!');

        \PHPWebSockets::Log(LogLevel::INFO, 'Will run ' . $this->_caseCount . ' test cases');

    }

    protected function tearDown() : void {

        \PHPWebSockets::Log(LogLevel::INFO, 'Tearing down');
        proc_terminate($this->_autobahnProcess);
        exec('docker container stop ' . escapeshellarg(self::CONTAINER_NAME));

    }

    public function testClient() : void {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting tests..');

        for ($i = 0; $i < $this->_caseCount; $i++) {

            $client = $this->_createClient();
            $client->connect($this->_serverURI, '/runCase?case=' . ($i + 1) . '&agent=' . $client->getUserAgent());

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
        $client->connect($this->_serverURI, '/updateReports?agent=' . $client->getUserAgent());

        while ($client->isOpen()) {
            foreach ($client->update(NULL) as $key => $value) {
                // Nothing, the remote will close it for us
            }
        }

        \PHPWebSockets::Log(LogLevel::INFO, 'Reports finished, getting results..');
        $outputFile = $this->_reportsDir . '/index.json';
        $this->assertFileExists($outputFile);

        $testCases = json_decode(file_get_contents($outputFile), TRUE)[$client->getUserAgent()] ?? NULL;
        $this->assertNotNull($testCases, 'Unable to get test case results');

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
