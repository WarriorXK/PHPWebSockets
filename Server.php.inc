<?php
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

declare(strict_types = 1);

namespace PHPWebSocket;
use \PHPWebSocket\Update;

require_once(__DIR__ . '/Server/AcceptingConnection.php.inc');
require_once(__DIR__ . '/Server/Connection.php.inc');

class Server {

    /**
     * The maximum size in bytes for the handshake
     * @var integer
     */
    const HANDSHAKE_MAXLENGTH = 8192;

    /**
     * The time in seconds in which the stream_socket_accept method has to accept the connection or fail
     * @var float
     */
    const SOCKET_ACCEPT_TIMEOUT = 1.0;

    /**
     * The time in seconds in which the client has to send its handshake
     * @var float
     */
    const ACCEPT_TIMEOUT = 1.0;

    /**
     * The accepting socket connection
     * @var \PHPWebSocket\Server\AcceptingConnection
     */
    protected $_acceptingConnection = NULL;

    /**
     * The index for the next connection to be inserted at
     * @var integer
     */
    protected $_connectionIndex = 0;

    /**
     * All connections
     * @var \PHPWebSocket\Server\Connection[]
     */
    protected $_connections = array();

    /**
     * The address of the accepting socket
     * @var string
     */
    protected $_address = NULL;

    /**
     * If we're using SSL
     * @var boolean
     */
    protected $_usesSSL = FALSE;

    /**
     * If we're currently accepting connections
     * @var boolean
     */
    protected $_open = FALSE;

    /**
     * Constructs a new webserver
     * @param  string       $address  This is either a IPv4 address or a full protocol://address:port scheme url
     * @param  integer|NULL $port     Defaults to 80, if an SLL certificate is provided it will default to 443, if the address is not a IPv4 address this parameter is ignored
     * @param  string|NULL  $sslCert  The filepath to the certificate
     * @throws \Exception
     */
    public function __construct(string $address = '0.0.0.0', int $port = NULL, string $sslCert = NULL) {

        $this->_usesSSL = ($sslCert !== NULL);
        if ($this->usesSSL() && !file_exists($sslCert)) {
            throw new \Exception('Unable to load certificate from path \'' . $sslCert . '\'');
        }

        $this->_address = $address;

        if (filter_var($this->_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

            $port = ($port ?: ($this->usesSSL() ? 443 : 80));
            $protocol = ($this->usesSSL() ? 'tls' : 'tcp');

            $this->_address = $protocol . '://' . $this->_address . ':' . $port;

        }

        $options = array();
        if ($this->usesSSL()) {

            $options['ssl'] = array(
                'allow_self_signed' => TRUE,
                'local_cert', $sslCert
            );

        }

        $address = $this->getAddress();
        $pos = strpos($address, '://');
        if ($pos !== FALSE) {

            $protocol = substr($address, 0, $pos);
            switch ($protocol) {
                case 'unix':
                case 'udg':

                    $path = substr($address, $pos + 3);
                    if (file_exists($path)) {

                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_WARNING, 'Unix socket "' . $path . '" still exists, unlinking!');
                        if (!unlink($path)) {
                            throw new \Exception('Unable to unlink file "' . $path . '"');
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

        $this->_open = TRUE;

    }

    /**
     * Checks for updates
     * @param  float|NULL $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     * @return \PHPWebSocket\AUpdate[]
     * @throws \Exception
     */
    public function update(float $timeout = NULL) : \Generator {
        yield from \PHPWebSocket::MultiUpdate($this->getConnections(TRUE), $timeout);
    }

    /**
     * Gets called by the accepting web socket to notify the server that a new connection attempt has occured
     * @param \PHPWebSocket\Server\AcceptingConnection $acceptingConnection
     */
    public function gotNewConnection(\PHPWebSocket\Server\AcceptingConnection $acceptingConnection) {

        if ($acceptingConnection !== $this->_acceptingConnection) {
            throw new \InvalidArgumentException('Got new connection from unknown accepting socket!');
        }

        $peername = '';
        $newStream = stream_socket_accept($acceptingConnection->getStream(), self::SOCKET_ACCEPT_TIMEOUT, $peername);
        if (!$newStream) {
            throw new \Exception('Unable to accept stream socket!');
        }

        $newConnection = new \PHPWebSocket\Server\Connection($this, $newStream, $peername, $this->_connectionIndex);
        $this->_connections[$this->_connectionIndex] = $newConnection;

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Got new connection: ' . $newConnection);

        $this->_connectionIndex++;

    }

    /**
     * Generates a error response for the provided code
     * @param  integer $errorCode
     * @param  string  $fallbackErrorString
     * @return string
     */
    public function getErrorPageForCode(int $errorCode, string $fallbackErrorString = 'Unknown error code') : string {

        $replaceFields = array(
            '%errorCode%' => (string) $errorCode,
            '%errorString%' => \PHPWebSocket::GetStringForErrorCode($errorCode) ?: $fallbackErrorString,
            '%serverIdentifier%' => $this->getServerIdentifier()
        );

        return str_replace(array_keys($replaceFields), array_values($replaceFields), "HTTP/1.1 %errorCode% %errorString%\r\nServer: %serverIdentifier%\r\n\r\n<html><head><title>%errorCode% %errorString%</title></head><body bgcolor='white'><center><h1>%errorCode% %errorString%</h1></center><hr><center>%serverIdentifier%</center></body></html>\r\n\r\n");
    }

    /**
     * Attempts to return the connection object related to the provided stream
     * @param  resource $stream
     * @return \PHPWebSocket\Server\Connection|NULL
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
     * @return string
     */
    public function getServerIdentifier() : string {
        return 'PHPWebSocket/' . \PHPWebSocket::Version();
    }

    /**
     * Returns if the provided connection in owned by this server
     * @return \PHPWebSocket\Server\Connection[]
     */
    public function hasConnection(Server\Connection $connection) : bool {
        return in_array($connection, $this->_connections, TRUE);
    }

    /**
     * Returns the accepting connection
     * @return \PHPWebSocket\Server\AcceptingConnection
     */
    public function getAcceptingConnection() : Server\AcceptingConnection {
        return $this->_acceptingConnection;
    }

    /**
     * Returns all connections this server has
     * @return \PHPWebSocket\Server\Connection[]
     */
    public function getConnections(bool $includeAccepting = FALSE) : array {

        $ret = $this->_connections;
        if ($includeAccepting) {

            $acceptingConnection = $this->getAcceptingConnection();
            if ($acceptingConnection->isOpen()) {
                $ret[] = $acceptingConnection;
            }

        }

        return $ret;
    }

    /**
     * Sends a disconnect message to all clients
     * @param  integer $closeCode
     * @param  string  $reason
     */
    public function disconnectAll(int $closeCode, string $reason = '') {

        foreach ($this->getConnections() as $connection) {
            $connection->sendDisconnect($closeCode, $reason);
        }

    }

    /**
     * Returns the bind address for this websocket
     * @return string
     */
    public function getAddress() : string {
        return $this->_address;
    }

    /**
     * Removes the specified connection from the connections array and closes it if open
     * @param \PHPWebSocket\Server\Connection $connection
     */
    public function removeConnection(Server\Connection $connection) {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Removing ' . $connection);

        if ($connection->isOpen()) {
            $connection->close();
        }
        unset($this->_connections[$connection->getIndex()]);

    }

    /**
     * Returns the maximum amount of bytes should be written per tick for the specified connection
     * @param  \PHPWebSocket\Server\Connection $connection
     * @return integer
     */
    public function getWriteRate(Server\Connection $connection) : int {
        return 16384;
    }

    /**
     * Returns the maximum amount of bytes should be read per tick for the specified connection
     * @param  \PHPWebSocket\Server\Connection $connection
     * @return integer
     */
    public function getReadRate(Server\Connection $connection) : int {
        return 16384;
    }

    /**
     * Returns if this webserver uses SSL
     * @return boolean
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
                $this->_acceptingConnection->close();
            }

            $this->_acceptingConnection = NULL;

        }

        $this->_open = FALSE;

    }

    public function __destruct() {
        $this->close();
    }

}
