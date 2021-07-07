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

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class UpdatesWrapperTest extends TestCase {

    protected const ADDRESS = 'tcp://127.0.0.1:9124';

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

    protected function setUp() : void {

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

            $this->assertIsInt($index);
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

            $this->assertIsInt($index);
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

            \PHPWebSockets::Log(LogLevel::INFO, 'Got error ' . $code . ' (' . \PHPWebSockets\Update\Error::StringForCode($code) . ') ' . ' from ' . $connection);

        });

        $this->_wsServer = new \PHPWebSockets\Server(self::ADDRESS);

    }

    protected function tearDown() : void {

        $this->_wsServer->close();

    }

    public function testWrapperNormal() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5 --async', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --die-at=' . escapeshellarg((string) $dieAt), $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --close-at=' . $closeAt . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=1', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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

    public function testWrapperAsyncClientTCPRefuse() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $this->_refuseNextConnection = TRUE;

        $runUntil = microtime(TRUE) + 8.0;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg('tcp://127.0.0.1:9000') . ' --message=' . escapeshellarg('Hello world') . ' --message-count=1 --async', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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

    public function testDelayedDoubleClose() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $clientCloseAt = microtime(TRUE) + 3.0;
        $serverSleepAt = $clientCloseAt - 1.0;
        $delay = 5;
        $runUntil = $clientCloseAt + $delay + 4.0;

        $didClose = FALSE;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --close-at=' . $clientCloseAt . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (microtime(TRUE) <= $runUntil) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

            if (microtime(TRUE) < $clientCloseAt) {
                $this->assertTrue(proc_get_status($clientProcess)['running'] ?? FALSE);
            }

            if (!$didClose && microtime(TRUE) >= $serverSleepAt) {

                \PHPWebSockets::Log(LogLevel::INFO, 'Sleeping..' . PHP_EOL);

                sleep($delay);

                \PHPWebSockets::Log(LogLevel::INFO, 'Closing' . PHP_EOL);

                $connections = $this->_wsServer->getConnections(FALSE);
                /** @var \PHPWebSockets\AConnection $connection */
                $connection = reset($connections);
                $connection->close();

                $didClose = TRUE;

            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testDisconnectAndClose() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $closeAt = microtime(TRUE) + 3.0;
        $runUntil = $closeAt + 4.0;

        $didSendDisconnect = FALSE;
        $didClose = FALSE;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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

    public function testSimultaneousDisconnect() {

        /*
         * Here we disconnect at the same time without updating in the meantime, simulating a "hang" in the server
         */

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $disconnectAt = microtime(TRUE) + 3.0;

        $didSendDisconnect = FALSE;
        $gotClient = FALSE;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/WSClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --disconnect-at=' . escapeshellarg((string) $disconnectAt) . ' --message=' . escapeshellarg('Hello world') . ' --message-count=5', $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (TRUE) {

            // Only update if we are waiting for a client, or 1 second after we've send the disconnect
            $shouldUpdate = (!$gotClient || ($didSendDisconnect && (microtime(TRUE) > $disconnectAt + 1)));

            if ($shouldUpdate) {
                \PHPWebSockets::Log(LogLevel::INFO, 'Updating');
                $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));
            } else {
                \PHPWebSockets::Log(LogLevel::INFO, 'Not updating');
                sleep(1);
            }

            if (!$didSendDisconnect) {
                $this->assertTrue(proc_get_status($clientProcess)['running'] ?? FALSE);
            }

            if (!empty($this->_wsServer->getConnections(FALSE))) {
                // Client has been accepted, stop updating
                $gotClient = TRUE;
            }

            if (microtime(TRUE) >= $disconnectAt && !$didSendDisconnect) {

                $connections = $this->_wsServer->getConnections(FALSE);

                $this->assertCount(1, $connections);

                \PHPWebSockets::Log(LogLevel::INFO, 'Sending disconnect');

                /** @var \PHPWebSockets\AConnection $connection */
                $connection = reset($connections);
                $connection->sendDisconnect(\PHPWebSockets::CLOSECODE_NORMAL);

                $didSendDisconnect = TRUE;

            }

            if (!proc_get_status($clientProcess)['running'] ?? FALSE) {
                break;
            }

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }

    public function testTCPClientConnect() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $closeAt = microtime(TRUE) + 3.0;
        $runUntil = $closeAt + 4.0;

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/SocketClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --close-at=' . $closeAt, $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

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

    public function testTCPClientBadData() {

        \PHPWebSockets::Log(LogLevel::INFO, 'Starting test..' . PHP_EOL);

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));

        $message = "Hello websocket server\r\n\r\nMah body";

        $descriptorSpec = [['pipe', 'r'], STDOUT, STDERR];
        $clientProcess = proc_open('./tests/Helpers/SocketClient.php --address=' . escapeshellarg(self::ADDRESS) . ' --message=' . escapeshellarg($message), $descriptorSpec, $pipes, realpath(__DIR__ . '/../'));

        while (proc_get_status($clientProcess)['running'] ?? FALSE) {

            $this->_updatesWrapper->update(0.5, $this->_wsServer->getConnections(TRUE));

        }

        $this->assertEmpty($this->_wsServer->getConnections(FALSE));
        $this->assertEmpty($this->_connectionList);

        \PHPWebSockets::Log(LogLevel::INFO, 'Test finished' . PHP_EOL);

    }
}
