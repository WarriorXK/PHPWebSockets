<?php

declare(strict_types = 1);

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2018 Kevin Meijer
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

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class UpdatesWrapperTest extends TestCase {

    const ADDRESS = 'tcp://127.0.0.1:9001';

    /**
     * @var bool
     */
    protected $_refuseNextConnection = FALSE;

    /**
     * @var \PHPWebSockets\AConnection[]
     */
    protected $_connectionList = [];

    /**
     * @var \PHPWebSockets\UpdatesWrapper|null
     */
    protected $_updatesWrapper = NULL;

    /**
     * The websocket server
     *
     * @var \PHPWebSockets\Server|null
     */
    protected $_wsServer = NULL;

    protected $_exit = FALSE;

    protected function setUp() {

        $this->_updatesWrapper = new \PHPWebSockets\UpdatesWrapper();
        $this->_updatesWrapper->setDisconnectHandler(function (\PHPWebSockets\AConnection $connection, bool $wasClean, int $code, string $reason) {

            /*
             * If a connection closes, check if we have the connection and remove it
             */

            \PHPWebSockets::Log(LogLevel::INFO, 'Disconnect ' . $connection);

            $this->assertContains($connection, $this->_connectionList);

            if ($connection instanceof \PHPWebSockets\Server\Connection) {
                $this->assertTrue($connection->getServer()->hasConnection($connection), 'Server doesn\'t have a reference to its connection!');
            }

            unset($this->_connectionList[$connection->getResourceIndex()]);

        });
        $this->_updatesWrapper->setClientConnectedHandler(function (\PHPWebSockets\Client $client) {

            /*
             * If a client has connected check if it doesn't already exist and add it to the list
             */

            \PHPWebSockets::Log(LogLevel::INFO, 'Connect ' . $client);

            $this->assertNotContains($client, $this->_connectionList);

            $index = $client->getResourceIndex();

            $this->assertInternalType('int', $index);
            $this->assertArrayNotHasKey($index, $this->_connectionList);

            $this->_connectionList[$index] = $client;

        });
        $this->_updatesWrapper->setNewConnectionHandler(function (\PHPWebSockets\Server\Connection $connection) {

            /*
             * If a client has connected to the server, check if it doesn't already exist and add it to the list
             */

            \PHPWebSockets::Log(LogLevel::INFO, 'Connect ' . $connection);

            $this->assertNotContains($connection, $this->_connectionList);

            $index = $connection->getResourceIndex();

            $this->assertInternalType('int', $index);
            $this->assertArrayNotHasKey($index, $this->_connectionList);

            if ($this->_refuseNextConnection) {

                \PHPWebSockets::Log(LogLevel::INFO, 'Denying ' . $connection);

                $connection->deny(500);

                $this->_refuseNextConnection = FALSE;

            } else {

                $connection->accept();

                $this->_connectionList[$index] = $connection;

            }

        });
        $this->_updatesWrapper->setLastContactHandler(function (\PHPWebSockets\AConnection $connection) {

            /*
             * Check if we have the connection
             */

            \PHPWebSockets::Log(LogLevel::INFO, 'Got contact ' . $connection);

            $this->assertContains($connection, $this->_connectionList);

        });
        $this->_updatesWrapper->setMessageHandler(function (\PHPWebSockets\AConnection $connection, string $message, int $opcode) {

            /*
             * Check if we have the connection and echo the received message
             */

            \PHPWebSockets::Log(LogLevel::INFO, 'Got message ' . $connection);

            $this->assertContains($connection, $this->_connectionList);

            $connection->write($message, $opcode);

        });
        $this->_updatesWrapper->setErrorHandler(function (\PHPWebSockets\AConnection $connection, int $code) {

            \PHPWebSockets::Log(LogLevel::INFO, 'Got error ' . $code . ' from ' . $connection);

        });

        $this->_wsServer = new \PHPWebSockets\Server(self::ADDRESS);

    }

    protected function tearDown() {

        $this->_wsServer->close();

    }

    public function testWrapperNormal() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (proc_get_status($clientProcess)['running'] ?? FALSE) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testWrapperAsyncClient() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5 --async', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (proc_get_status($clientProcess)['running'] ?? FALSE) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testWrapperClientDisappeared() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $dieAt = microtime(TRUE) + 3.0;
        $runUntil = $dieAt + 4.0;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --die-at=' . escapeshellarg((string) $dieAt), $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (microtime(TRUE) <= $runUntil) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if ($clientProcess !== NULL) {

                $status = proc_get_status($clientProcess);
                if (!$status['running']) {

                    \PHPWebSockets::Log(LogLevel::INFO, 'Client disappeared');
                    $clientProcess = NULL;

                }

            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testWrapperServerClose() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $closeAt = microtime(TRUE) + 3.0;
        $runUntil = $closeAt + 4.0;

        $didClose = FALSE;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (microtime(TRUE) <= $runUntil) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if (!$didClose) {
                $this->assertTrue(proc_get_status($clientProcess)['running'] ?? FALSE);
            }

            if (microtime(TRUE) >= $closeAt && !$didClose) {

                $connections = $this->_wsServer->getConnections(FALSE);

                $this->assertNotEmpty($connections);

                \PHPWebSockets::Log(LogLevel::INFO, 'Closing connection');

                $connection = reset($connections);
                $connection->close();

                $didClose = TRUE;

            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testWrapperClientClose() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $closeAt = microtime(TRUE) + 3.0;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --close-at=' . $closeAt . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (TRUE) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if (!proc_get_status($clientProcess)['running'] ?? FALSE) {
                break;
            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testWrapperClientRefuse() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $this->_refuseNextConnection = TRUE;

        $runUntil = microtime(TRUE) + 8.0;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=1', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (microtime(TRUE) <= $runUntil) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if ($clientProcess !== NULL) {

                $status = proc_get_status($clientProcess);
                if (!$status['running']) {

                    \PHPWebSockets::Log(LogLevel::INFO, 'Client disappeared');
                    $clientProcess = NULL;

                }

            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        $this->_refuseNextConnection = FALSE;

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testDoubleClose() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $closeAt = microtime(TRUE) + 3.0;
        $runUntil = $closeAt + 4.0;

        $didSendDisconnect = FALSE;
        $didClose = FALSE;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/client.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (microtime(TRUE) <= $runUntil) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if (!$didSendDisconnect) {
                $this->assertTrue(proc_get_status($clientProcess)['running'] ?? FALSE);
            }

            if ($didSendDisconnect && !$didClose) {

                /** @var \PHPWebSockets\AConnection $connection */
                $connection = reset($connections);
                $connection->close();

                $didClose = TRUE;

            }

            if (microtime(TRUE) >= $closeAt && !$didSendDisconnect) {

                $connections = $this->_wsServer->getConnections(FALSE);

                $this->assertNotEmpty($connections);

                \PHPWebSockets::Log(LogLevel::INFO, 'Sending disconnect + close');

                /** @var \PHPWebSockets\AConnection $connection */
                $connection = reset($connections);
                $connection->sendDisconnect(\PHPWebSockets::CLOSECODE_NORMAL);

                $didSendDisconnect = TRUE;

            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }
}
