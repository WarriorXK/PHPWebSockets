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

require_once(__DIR__ . '/Connection.php.inc');

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
     * The accepting socket connection
     * @var resource
     */
    protected $_mainSocket = NULL;

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
     * @param  string       $address Defaults to all adapters
     * @param  integer|NULL $port    Defaults to 80, if an SLL certificate is provided it will default to 443
     * @param  string|NULL  $sslCert The filepath to the certificate
     * @throws \Exception
     */
    public function __construct(string $address = '0.0.0.0', int $port = NULL, string $sslCert = NULL) {

        $this->_usesSSL = ($sslCert !== NULL);
        if ($this->usesSSL() && !file_exists($sslCert)) {
            throw new \Exception('Unable to load certificate from path \'' . $sslCert . '\'');
        }

        $port = ($port ?? ($this->_usesSSL ? 443 : 80));
        $protocol = ($this->_usesSSL ? 'tls' : 'tcp');

        $options = array();
        if ($this->usesSSL()) {

            $options['ssl'] = array(
                'allow_self_signed' => TRUE,
                'local_cert', $sslCert
            );

        }

        $errCode = NULL;
        $errString = NULL;
        $this->_mainSocket = @stream_socket_server($protocol . '://' . $address . ':' . $port, $errCode, $errString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($options));
        if (!$this->_mainSocket) {
            throw new \Exception('Unable to create webserver: ' . $errString, $errCode);
        }

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_NORMAL, 'Opened webserver on ' . $address . ':' . $port, TRUE);

        stream_set_timeout($this->_mainSocket, 1);
        stream_set_blocking($this->_mainSocket, FALSE);
        stream_set_read_buffer($this->_mainSocket, 0);
        stream_set_write_buffer($this->_mainSocket, 0);

        $this->_open = TRUE;

    }

    /**
     * Checks for updates
     * @param  float|NULL $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     * @return \PHPWebSocket\AUpdate[]
     * @throws \Exception
     */
    public function update(float $timeout = 0.1) : \Generator {

        $timeInt = NULL;
        $timePart = 0;

        if ($timeout !== NULL) {

            $timeInt = (int) floor($timeout);
            $timePart = (int) (fmod($timeout, 1) * 1000000);

        }

        $connectionsToRemove = array();
        $exceptional = array();
        $write = array();
        $read = array($this->_mainSocket);

        foreach ($this->_connections as $index => $connection) { // Add all sockets to the arrays that need stuff done

            if (!$connection->isAccepted() && $connection->hasHandshake() && $connection->getOpenedTimestamp() + self::ACCEPT_TIMEOUT < time()) {

                yield new Update\Error(Update\Error::C_ACCEPT_TIMEOUT_PASSED, $connection);

                $connectionsToRemove[] = $connection;
                $connection->deny(408);

            }

            $read[] = $connection->getStream();

            if (!$connection->isWriteBufferEmpty()) {
                $write[] = $connection->getStream();
            }

        }

        if (!empty($read) || !empty($write) || !empty($exceptional)) {

            $streams = @stream_select($read, $write, $exceptional, $timeInt, $timePart); // Stream select filters everything out of the arrays
            if ($streams === FALSE) {
                yield new Update\Error(Update\Error::C_SELECT);
                return;
            } else {

                if (!empty($read) || !empty($write) || !empty($exceptional)) {
                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Read: ' . count($read) . ' Write: ' . count($write) . ' Except: ' . count($exceptional));
                }

                foreach ($read as $stream) {

                    if ($stream === $this->_mainSocket) { // The main socket only accepts

                        $peername = '';
                        $newStream = stream_socket_accept($stream, self::SOCKET_ACCEPT_TIMEOUT, $peername);
                        if (!$newStream) {
                            throw new \Exception('Unable to accept stream socket!');
                        }

                        $newConnection = new \PHPWebSocket\Server\Connection($this, $newStream, $peername, $this->_connectionIndex);
                        $this->_connections[$this->_connectionIndex] = $newConnection;

                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Got new connection: ' . $newConnection);

                        $this->_connectionIndex++;

                    } else {

                        $connection = $this->getConnectionByStream($stream);
                        if ($connection === NULL) {
                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find connection related to stream during read!');
                            continue;
                        }

                        yield from $connection->handleRead();

                    }

                }

                foreach ($write as $stream) {

                    $connection = $this->getConnectionByStream($stream);
                    if ($connection === NULL) {
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find connection related to stream during write!');
                        continue;
                    }

                    $writeRes = $connection->handleWrite();
                    if ($writeRes !== NULL) {
                        yield $writeRes;
                    }

                }

                foreach ($exceptional as $stream) {

                    $connection = $this->getConnectionByStream($stream);
                    if ($connection === NULL) {
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find connection related to stream during exceptional read!');
                        continue;
                    }

                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_WARNING, 'Got exceptional for ' . $connection);

                    yield from $connection->handleExceptional();

                }

            }

        }

        foreach ($connectionsToRemove as $connection) {
            $this->removeConnection($connection);
        }

        return;
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
            '%serverIdentifier%' => 'PHPWebSocket/' . \PHPWebSocket::Version()
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
     * Returns if the provided connection in owned by this server
     * @return \PHPWebSocket\Server\Connection[]
     */
    public function hasConnection(Server\Connection $connection) : bool {
        return in_array($connection, $this->_connections, TRUE);
    }

    /**
     * Returns all connections this server has
     * @return \PHPWebSocket\Server\Connection[]
     */
    public function getConnections() : array {
        return $this->_connections;
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

        if (is_resource($this->_mainSocket)) {
            fclose($this->_mainSocket);
            $this->_mainSocket = NULL;
        }

        $this->_open = FALSE;

    }

    public function __destruct() {
        $this->close();
    }

}
