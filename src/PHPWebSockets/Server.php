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

namespace PHPWebSockets;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LogLevel;

class Server implements LoggerAwareInterface {

    use TLogAware;

    /**
     * The counter to provide all websocket servers with an unique ID
     *
     * @var int
     */
    private static $_ServerCounter = 0;

    /**
     * If we should cleanup the accepting connection when we close it
     *
     * @var bool
     */
    protected $_cleanupAcceptingConnectionOnClose = TRUE;

    /**
     * The time in seconds in which the stream_socket_accept method has to accept the connection or fail
     *
     * @var float
     */
    protected $_socketAcceptTimeout = 5.0;

    /**
     * The accepting socket connection
     *
     * @var \PHPWebSockets\Server\AcceptingConnection
     */
    protected $_acceptingConnection = NULL;

    /**
     * If we should disable the after fork cleanup
     *
     * @var bool
     */
    protected $_disableForkCleanup = NULL;

    /**
     * The identifier shown to connecting clients, when set to NULL the string PHPWebSockets/<ModuleVersion> will be used
     *
     * @var string|null
     */
    protected $_serverIdentifier = NULL;

    /**
     * The FQCN of the class to use for new connections
     *
     * @var string
     */
    protected $_connectionClass = Server\Connection::class;

    /**
     * The index for the next connection to be inserted at
     *
     * @var int
     */
    protected $_connectionIndex = 0;

    /**
     * All connections
     *
     * @var \PHPWebSockets\Server\Connection[]
     */
    protected $_connections = [];

    /**
     * The unique ID for this server
     *
     * @var int
     */
    protected $_serverIndex = 0;

    /**
     * If the new connection should automatically be accepted
     *
     * @var bool
     */
    protected $_autoAccept = TRUE;

    /**
     * If we should enable crypto after accept
     *
     * @var bool
     */
    protected $_useCrypto = FALSE;

    /**
     * The address of the accepting socket
     *
     * @var string
     */
    protected $_address = NULL;

    /**
     * Constructs a new webserver
     *
     * @param string $address       This should be a protocol://address:port scheme url, if left NULL no accepting socket will be created
     * @param array  $streamContext The streamcontext @see https://secure.php.net/manual/en/function.stream-context-create.php
     * @param bool   $useCrypto     If we should enable crypto on newly accepted connections
     *
     * @throws \RuntimeException
     */
    public function __construct(string $address = NULL, array $streamContext = [], bool $useCrypto = FALSE) {

        $this->_serverIndex = self::$_ServerCounter;
        $this->_useCrypto = $useCrypto;
        $this->_address = $address;

        self::$_ServerCounter++;

        if ($this->_address !== NULL) {

            $pos = strpos($this->_address, '://');
            if ($pos !== FALSE) {

                $protocol = substr($this->_address, 0, $pos);
                switch ($protocol) {
                    case 'unix':
                    case 'udg':

                        $path = substr($this->_address, $pos + 3);
                        if (file_exists($path)) {

                            $this->_log(LogLevel::WARNING, 'Unix socket "' . $path . '" still exists, unlinking!');
                            if (!unlink($path)) {
                                throw new \RuntimeException('Unable to unlink file "' . $path . '"');
                            }

                        } else {

                            $dir = pathinfo($path, PATHINFO_DIRNAME);
                            if (!is_dir($dir)) {

                                $this->_log(LogLevel::DEBUG, 'Directory "' . $dir . '" does not exist, creating..');
                                mkdir($dir, 0770, TRUE);

                            }

                        }

                        break;
                }

            }

            $errCode = NULL;
            $errString = NULL;
            $acceptingSocket = @stream_socket_server($this->_address, $errCode, $errString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($streamContext));
            if (!$acceptingSocket) {
                throw new \RuntimeException('Unable to create webserver: ' . $errString, $errCode);
            }

            $this->_acceptingConnection = new Server\AcceptingConnection($this, $acceptingSocket);

            $this->_log(LogLevel::INFO, 'Opened websocket on ' . $this->_address);

        }

    }

    /**
     * Creates a new client/connection pair to be used in fork communication
     *
     * @return array
     */
    public function createServerClientPair() : array {

        list($server, $client) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        /** @var \PHPWebSockets\Server\Connection $serverConnection */
        $serverConnection = new $this->_connectionClass($this, $server, '', $this->_connectionIndex);
        $this->_connections[$this->_connectionIndex] = $serverConnection;

        $this->_log(LogLevel::DEBUG, 'Created new connection: ' . $serverConnection);

        $this->_connectionIndex++;

        $clientConnection = new Client();
        $clientConnection->connectToResource($client);

        return [$serverConnection, $clientConnection];
    }

    /**
     * Checks for updates
     *
     * @param float|null $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @throws \Exception
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function update(float $timeout = NULL) : \Generator {
        yield from \PHPWebSockets::MultiUpdate($this->getConnections(TRUE), $timeout);
    }

    /**
     * Gets called by the accepting web socket to notify the server that a new connection attempt has occured
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function gotNewConnection() : \Generator {

        if (!$this->_autoAccept) {
            yield new Update\Read(Update\Read::C_NEW_TCP_CONNECTION_AVAILABLE, $this->_acceptingConnection);
        } else {
            yield from $this->acceptNewConnection();
        }

    }

    /**
     * Accepts a new connection from the accepting socket
     *
     * @throws \LogicException
     * @throws \RuntimeException
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function acceptNewConnection() : \Generator {

        if ($this->_acceptingConnection === NULL) {
            throw new \LogicException('This server has no accepting connection, unable to accept a new connection!');
        }

        $peername = '';
        $newStream = stream_socket_accept($this->_acceptingConnection->getStream(), $this->getSocketAcceptTimeout(), $peername);
        if (!$newStream) {
            throw new \RuntimeException('Unable to accept stream socket!');
        }

        $newConnection = new $this->_connectionClass($this, $newStream, $peername, $this->_connectionIndex);
        $this->_connections[$this->_connectionIndex] = $newConnection;

        $this->_log(LogLevel::DEBUG, 'Got new connection: ' . $newConnection);

        $this->_connectionIndex++;

        yield new Update\Read(Update\Read::C_NEW_TCP_CONNECTION, $newConnection);

    }

    /**
     * Generates a error response for the provided code
     *
     * @param int    $errorCode
     * @param string $fallbackErrorString
     *
     * @return string
     */
    public function getErrorPageForCode(int $errorCode, string $fallbackErrorString = 'Unknown error code') : string {

        $replaceFields = [
            '%errorCode%'        => (string) $errorCode,
            '%errorString%'      => \PHPWebSockets::GetStringForStatusCode($errorCode) ?: $fallbackErrorString,
            '%serverIdentifier%' => $this->getServerIdentifier(),
        ];

        return str_replace(array_keys($replaceFields), array_values($replaceFields), "HTTP/1.1 %errorCode% %errorString%\r\nServer: %serverIdentifier%\r\n\r\n<html><head><title>%errorCode% %errorString%</title></head><body bgcolor='white'><h1 align=\"center\">%errorCode% %errorString%</h1><hr><div align=\"center\">%serverIdentifier%</div></body></html>\r\n\r\n");
    }

    /**
     * Attempts to return the connection object related to the provided stream
     *
     * @param resource $stream
     *
     * @return Server\Connection|null
     */
    public function getConnectionByStream($stream) {

        foreach ($this->_connections as $connection) {

            if ($stream === $connection->getStream()) {
                return $connection;
            }

        }

        return NULL;
    }

    /**
     * Returns the server identifier string reported to clients
     *
     * @return string
     */
    public function getServerIdentifier() : string {
        return $this->_serverIdentifier ?? 'PHPWebSockets/' . \PHPWebSockets::Version();
    }

    /**
     * Sets the server identifier string reported to clients
     *
     * @param string|null $identifier
     */
    public function setServerIdentifier(string $identifier = NULL) {
        $this->_serverIdentifier = $identifier;
    }

    /**
     * Returns if the provided connection in owned by this server
     *
     * @param \PHPWebSockets\Server\Connection $connection
     *
     * @return bool
     */
    public function hasConnection(Server\Connection $connection) : bool {
        return in_array($connection, $this->_connections, TRUE);
    }

    /**
     * Returns the accepting connection
     *
     * @return \PHPWebSockets\Server\AcceptingConnection|null
     */
    public function getAcceptingConnection() {
        return $this->_acceptingConnection;
    }

    /**
     * Returns all connections this server has
     *
     * @param bool $includeAccepting
     *
     * @return array|\PHPWebSockets\Server\Connection[]
     */
    public function getConnections(bool $includeAccepting = FALSE) : array {

        $ret = $this->_connections;
        if ($includeAccepting) {

            $acceptingConnection = $this->getAcceptingConnection();
            if ($acceptingConnection !== NULL && $acceptingConnection->isOpen()) {
                array_unshift($ret, $acceptingConnection); // Insert the accepting connection on the first index
            }

        }

        return $ret;
    }

    /**
     * Sends a disconnect message to all clients
     *
     * @param int    $closeCode
     * @param string $reason
     *
     * @throws \Exception
     */
    public function disconnectAll(int $closeCode, string $reason = '') {

        foreach ($this->getConnections() as $connection) {
            $connection->sendDisconnect($closeCode, $reason);
        }

    }

    /**
     * Returns the bind address for this websocket
     *
     * @return string
     */
    public function getAddress() : string {
        return $this->_address;
    }

    /**
     * This should be called after a process has been fork with the PID returned from pcntl_fork, this ensures that the connection is closed in the new fork without interupting the main process
     *
     * @param int $pid
     */
    public function processDidFork(int $pid) {

        if ($this->_disableForkCleanup) {
            return;
        }

        if ($pid === 0) { // We are in the new fork

            $this->_cleanupAcceptingConnectionOnClose = FALSE;
            $this->close();

        }

    }

    /**
     * Removes the specified connection from the connections array and closes it if open
     *
     * @param \PHPWebSockets\Server\Connection $connection
     * @param bool                             $closeConnection
     *
     * @throws \LogicException
     */
    public function removeConnection(Server\Connection $connection, bool $closeConnection = TRUE) {

        if ($connection->getServer() !== $this) {
            throw new \LogicException('Unable to remove connection ' . $connection . ', this is not our connection!');
        }

        $this->_log(LogLevel::DEBUG, 'Removing ' . $connection);

        if ($closeConnection && $connection->isOpen()) {
            $connection->close();
        }

        unset($this->_connections[$connection->getIndex()]);

    }

    /**
     * Sets the time in seconds in which the stream_socket_accept method has to accept the connection or fail
     *
     * @param float $timeout
     */
    public function setSocketAcceptTimeout(float $timeout) {
        $this->_socketAcceptTimeout = $timeout;
    }

    /**
     * Returns the time in seconds in which the stream_socket_accept method has to accept the connection or fail
     *
     * @return float
     */
    public function getSocketAcceptTimeout() : float {
        return $this->_socketAcceptTimeout;
    }

    /**
     * Sets if we should disable the cleanup which happens after forking
     *
     * @param bool $disableForkCleanup
     */
    public function setDisableForkCleanup(bool $disableForkCleanup) {
        $this->_disableForkCleanup = $disableForkCleanup;
    }

    /**
     * Returns if we should disable the cleanup which happens after forking
     *
     * @return bool
     */
    public function getDisableForkCleanup() : bool {
        return $this->_disableForkCleanup;
    }

    /**
     * Sets if we should automatically accept the TCP connection
     *
     * @param bool $autoAccept
     */
    public function setAutoAccept(bool $autoAccept) {
        $this->_autoAccept = $autoAccept;
    }

    /**
     * Sets the class that will be our connection, this has to be an extension of \PHPWebSockets\Server\Connection
     *
     * @param string $class
     */
    public function setConnectionClass(string $class) {

        if (!is_subclass_of($class, Server\Connection::class, TRUE)) {
            throw new \InvalidArgumentException('The provided class has to extend ' . Server\Connection::class);
        }

        $this->_connectionClass = $class;

    }

    /**
     * Returns if we accept the TCP connection automatically
     *
     * @return bool
     */
    public function getAutoAccept() : bool {
        return $this->_autoAccept;
    }

    /**
     * Returns if we enable crypto after stream_socket_accept
     *
     * @return bool
     */
    public function usesCrypto() : bool {
        return $this->_useCrypto;
    }

    /**
     * Closes the webserver, note: clients should be notified beforehand that we are disconnecting, calling close while having connected clients will result in an improper disconnect
     */
    public function close() {

        foreach ($this->_connections as $connection) {
            $connection->close();
        }

        if ($this->_acceptingConnection !== NULL) {

            if ($this->_acceptingConnection->isOpen()) {
                $this->_acceptingConnection->close($this->_cleanupAcceptingConnectionOnClose);
            }

            $this->_acceptingConnection = NULL;

        }

    }

    public function __destruct() {
        $this->close();
    }

    public function __toString() {
        return 'WSServer ' . $this->_serverIndex;
    }
}
