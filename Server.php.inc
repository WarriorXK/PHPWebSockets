<?php

declare(strict_types = 1);

// - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
// The MIT License (MIT)
//
// Copyright (c) 2016 Kevin Meijer
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.
// - - - - - - - - - - - - - - END LICENSE BLOCK - - - - - - - - - - - - -

namespace PHPWebSocket;

require_once(__DIR__ . '/Server/AcceptingConnection.php.inc');
require_once(__DIR__ . '/Server/Connection.php.inc');

class Server {

    /**
     * The maximum size in bytes for the handshake
     *
     * @var int
     */
    const HANDSHAKE_MAXLENGTH = 8192;

    /**
     * The time in seconds in which the stream_socket_accept method has to accept the connection or fail
     *
     * @var float
     */
    const SOCKET_ACCEPT_TIMEOUT = 1.0;

    /**
     * The time in seconds in which the client has to send its handshake
     *
     * @var float
     */
    const ACCEPT_TIMEOUT = 1.0;

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
     * The accepting socket connection
     *
     * @var \PHPWebSocket\Server\AcceptingConnection
     */
    protected $_acceptingConnection = NULL;

    /**
     * If we should disable the after fork cleanup
     *
     * @var bool
     */
    protected $_disableForkCleanup = NULL;

    /**
     * The index for the next connection to be inserted at
     *
     * @var int
     */
    protected $_connectionIndex = 0;

    /**
     * All connections
     *
     * @var \PHPWebSocket\Server\Connection[]
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
     * The address of the accepting socket
     *
     * @var string
     */
    protected $_address = NULL;

    /**
     * If we're using SSL
     *
     * @var bool
     */
    protected $_usesSSL = FALSE;

    /**
     * If we're currently accepting connections
     *
     * @var bool
     */
    protected $_open = FALSE;

    /**
     * Constructs a new webserver
     *
     * @param string $address This should be a protocol://address:port scheme url, if left NULL no accepting socket will be created
     *
     * @throws \Exception
     */
    public function __construct(string $address = NULL) {

        $this->_serverIndex = self::$_ServerCounter;
        $this->_address = $address;

        self::$_ServerCounter++;

        $options = [];
        if ($this->usesSSL()) {

            $options['ssl'] = [
                'allow_self_signed' => TRUE,
                'local_cert', $sslCert,
            ];

        }

        if ($this->_address !== NULL) {

            $pos = strpos($this->_address, '://');
            if ($pos !== FALSE) {

                $protocol = substr($this->_address, 0, $pos);
                switch ($protocol) {
                    case 'unix':
                    case 'udg':

                        $path = substr($this->_address, $pos + 3);
                        if (file_exists($path)) {

                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_WARNING, 'Unix socket "' . $path . '" still exists, unlinking!');
                            if (!unlink($path)) {
                                throw new \Exception('Unable to unlink file "' . $path . '"');
                            }

                        } else {

                            $dir = pathinfo($path, PATHINFO_DIRNAME);
                            if (!is_dir($dir)) {

                                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Directory "' . $dir . '" does not exist, creating..');
                                mkdir($dir, 0770, TRUE);

                            }

                        }

                        break;
                }

            }

            $errCode = NULL;
            $errString = NULL;
            $acceptingSocket = @stream_socket_server($this->_address, $errCode, $errString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($options));
            if (!$acceptingSocket) {
                throw new \Exception('Unable to create webserver: ' . $errString, $errCode);
            }

            $this->_acceptingConnection = new Server\AcceptingConnection($this, $acceptingSocket);

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_NORMAL, 'Opened websocket on ' . $this->_address, TRUE);

        }

        $this->_open = TRUE;

    }

    /**
     * Creates a new client/connection pair to be used in fork communication
     *
     * @return array
     */
    public function createServerClientPair() : array {

        list($server, $client) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $serverConnection = new \PHPWebSocket\Server\Connection($this, $server, '', $this->_connectionIndex);
        $this->_connections[$this->_connectionIndex] = $serverConnection;

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Created new connection: ' . $serverConnection);

        $this->_connectionIndex++;

        $clientConnection = new \PHPWebSocket\Client();
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
     * @return \PHPWebSocket\AUpdate[]
     */
    public function update(float $timeout = NULL) : \Generator {
        yield from \PHPWebSocket::MultiUpdate($this->getConnections(TRUE), $timeout);
    }

    /**
     * Gets called by the accepting web socket to notify the server that a new connection attempt has occured
     *
     * @return \PHPWebSocket\AUpdate[]
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
     * @throws \Exception
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function acceptNewConnection() : \Generator {

        if ($this->_acceptingConnection === NULL) {
            throw new \Exception('This server has no accepting connection, unable to accept a new connection!');
        }

        $peername = '';
        $newStream = stream_socket_accept($this->_acceptingConnection->getStream(), self::SOCKET_ACCEPT_TIMEOUT, $peername);
        if (!$newStream) {
            throw new \Exception('Unable to accept stream socket!');
        }

        $newConnection = new \PHPWebSocket\Server\Connection($this, $newStream, $peername, $this->_connectionIndex);
        $this->_connections[$this->_connectionIndex] = $newConnection;

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Got new connection: ' . $newConnection);

        $this->_connectionIndex++;

        yield new Update\Read(Update\Read::C_NEW_TCP_CONNECTION, $this->_acceptingConnection);

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
            '%errorString%'      => \PHPWebSocket::GetStringForErrorCode($errorCode) ?: $fallbackErrorString,
            '%serverIdentifier%' => $this->getServerIdentifier(),
        ];

        return str_replace(array_keys($replaceFields), array_values($replaceFields), "HTTP/1.1 %errorCode% %errorString%\r\nServer: %serverIdentifier%\r\n\r\n<html><head><title>%errorCode% %errorString%</title></head><body bgcolor='white'><center><h1>%errorCode% %errorString%</h1></center><hr><center>%serverIdentifier%</center></body></html>\r\n\r\n");
    }

    /**
     * Attempts to return the connection object related to the provided stream
     *
     * @param resource $stream
     *
     * @return \PHPWebSocket\Server\Connection|null
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
        return 'PHPWebSocket/' . \PHPWebSocket::Version();
    }

    /**
     * Returns if the provided connection in owned by this server
     *
     * @return \PHPWebSocket\Server\Connection[]
     */
    public function hasConnection(Server\Connection $connection) : bool {
        return in_array($connection, $this->_connections, TRUE);
    }

    /**
     * Returns the accepting connection
     *
     * @return \PHPWebSocket\Server\AcceptingConnection|null
     */
    public function getAcceptingConnection() {
        return $this->_acceptingConnection;
    }

    /**
     * Returns all connections this server has
     *
     * @return \PHPWebSocket\Server\Connection[]
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
     * @param \PHPWebSocket\Server\Connection $connection
     */
    public function removeConnection(Server\Connection $connection) {

        if ($connection->getServer() !== $this) {
            throw new \LogicException('Unable to remove connection ' . $connection . ', this is not our connection!');
        }

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Removing ' . $connection);

        if ($connection->isOpen()) {
            $connection->close();
        }

        unset($this->_connections[$connection->getIndex()]);

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
     * Returns if we accept the TCP connection automatically
     *
     * @return bool
     */
    public function getAutoAccept() : bool {
        return $this->_autoAccept;
    }

    /**
     * Returns if this webserver uses SSL
     *
     * @return bool
     */
    public function usesSSL() : bool {
        return $this->_usesSSL;
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

        $this->_open = FALSE;

    }

    public function __destruct() {
        $this->close();
    }

    public function __toString() {
        return 'WSServer ' . $this->_serverIndex;
    }
}
