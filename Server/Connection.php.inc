<?php

declare(strict_types = 1);

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

namespace PHPWebSocket\Server;

use PHPWebSocket\Update;

class Connection extends \PHPWebSocket\AConnection {

    /**
     * The stream's resource index
     *
     * @var int|null
     */
    protected $_resourceIndex = NULL;

    /**
     * If we've finished the handshake
     *
     * @var bool
     */
    protected $_hasHandshake = FALSE;

    /**
     * If the connection has been accepted
     *
     * @var bool
     */
    protected $_accepted = FALSE;

    /**
     * The remote IP
     *
     * @var string|null
     */
    protected $_remoteIP = NULL;

    /**
     * The websocket token
     *
     * @var string
     */
    protected $_rawToken = NULL;

    /**
     * The headers sent during the handshake
     *
     * @var array
     */
    protected $_headers = NULL;

    /**
     * The websocket server related to this connection
     *
     * @var \PHPWebSocket\Server
     */
    protected $_server = NULL;

    /**
     * The resource stream
     *
     * @var resource
     */
    protected $_stream = NULL;

    /**
     * The connection's index in the connections array
     *
     * @var int
     */
    private $_index = NULL;

    public function __construct(\PHPWebSocket\Server $server, $stream, string $streamName, int $index) {

        $this->_openedTimestamp = microtime(TRUE);
        $this->_server = $server;
        $this->_remoteIP = parse_url($streamName, PHP_URL_HOST);
        $this->_stream = $stream;
        $this->_index = $index;

        $this->_resourceIndex = (int) $this->_stream;

        // The crypto enable HAS to happen before disabling blocking mode
        if ($server->usesCrypto()) {
            stream_socket_enable_crypto($this->_stream, TRUE, STREAM_CRYPTO_METHOD_TLS_SERVER);
        }

        stream_set_timeout($this->_stream, 15);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

    }

    /**
     * Attempts to read from our connection
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleRead() : \Generator {

        \PHPWebSocket::Log(LOG_DEBUG, __METHOD__);

        $readRate = $this->getReadRate($this);
        $newData = fread($this->getStream(), min($this->_currentFrameRemainingBytes ?? $readRate, $readRate));
        if ($newData === FALSE) {
            yield new Update\Error(Update\Error::C_READ, $this);

            return;
        }

        $updates = [];
        if (strlen($newData) === 0) {

            if ($this->_remoteSentDisconnect && $this->_weSentDisconnect) {
                yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);
            } else {
                yield new Update\Error(Update\Error::C_READ_UNEXPECTED_DISCONNECT, $this);
            }

            $this->close();

            return;

        } else {

            $hasHandshake = $this->hasHandshake();
            if (!$hasHandshake) {

                $headersEnd = strpos($newData, "\r\n\r\n");
                if ($headersEnd === FALSE) {

                    \PHPWebSocket::Log(LOG_DEBUG, 'Handshake data hasn\'t finished yet, waiting..');

                    if ($this->_readBuffer === NULL) {
                        $this->_readBuffer = '';
                    }

                    $this->_readBuffer .= $newData;

                    if (strlen($this->_readBuffer) > $this->getMaxHandshakeLength()) {

                        $this->writeRaw($this->_server->getErrorPageForCode(431), FALSE); // Request Header Fields Too Large
                        $this->setCloseAfterWrite();

                        yield new Update\Error(Update\Error::C_READ_HANDSHAKETOLARGE, $this);

                    }

                    return; // Still waiting for headers
                }

                if ($this->_readBuffer !== NULL) {

                    $newData = $this->_readBuffer . $newData;
                    $this->_readBuffer = NULL;

                }

                $rawHandshake = substr($newData, 0, $headersEnd);

                if (strlen($newData) > strlen($rawHandshake)) {
                    $newData = substr($newData, $headersEnd + 4);
                }

                $responseCode = 0;
                if ($this->_doHandshake($rawHandshake, $responseCode)) {
                    yield new Update\Read(Update\Read::C_NEWCONNECTION, $this);
                } else {

                    $this->writeRaw($this->_server->getErrorPageForCode($responseCode), FALSE);
                    $this->setCloseAfterWrite();

                    yield new Update\Error(Update\Error::C_READ_HANDSHAKEFAILURE, $this);

                }

                $hasHandshake = $this->hasHandshake();

            }

            if ($hasHandshake) {
                yield from $this->_handlePacket($newData);
            }

        }

    }

    /**
     * Gets called just before stream_select gets called
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function beforeStreamSelect() : \Generator {

        if (!$this->isAccepted() && $this->hasHandshake() && $this->getOpenedTimestamp() + \PHPWebSocket\Server::ACCEPT_TIMEOUT < time()) {

            yield new Update\Error(Update\Error::C_ACCEPT_TIMEOUT_PASSED, $this);
            $this->deny(408); // Request Timeout

        }

    }

    /**
     * Handles the handshake from the client and returns if the handshake was valid
     *
     * @param string $rawHandshake
     * @param int    &$responseCode
     *
     * @return bool
     */
    protected function _doHandshake(string $rawHandshake, int &$responseCode) : bool {

        $headers = \PHPWebSocket::ParseHTTPHeaders($rawHandshake);

        $responseCode = 101;
        if (!isset($headers['get'])) {
            $responseCode = 405; // Method Not Allowed
        } elseif (!isset($headers['host'])) {
            $responseCode = 400; // Bad Request
        } elseif (!isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket') {
            $responseCode = 400; // Bad Request
        } elseif (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE) {
            $responseCode = 400; // Bad Request
        } elseif (!isset($headers['sec-websocket-key'])) {
            $responseCode = 400; // Bad Request
        } elseif (!isset($headers['sec-websocket-version']) || intval($headers['sec-websocket-version']) !== 13) {
            $responseCode = 426; // Upgrade Required
        }

        $this->_headers = $headers;

        if ($responseCode >= 300) {
            return FALSE;
        }

        $this->_hasHandshake = TRUE;

        $hash = sha1($headers['sec-websocket-key'] . \PHPWebSocket::WEBSOCKET_GUID);
        $this->_rawToken = '';
        for ($i = 0; $i < 20; $i++) {
            $this->_rawToken .= chr(hexdec(substr($hash, $i * 2, 2)));
        }

        return TRUE;
    }

    /**
     * Accepts the connection
     *
     * @param string|null $protocol The accepted protocol
     *
     * @throws \Exception
     */
    public function accept(string $protocol = NULL) {

        if ($this->isAccepted()) {
            throw new \Exception('Connection has already been accepted!');
        }

        $misc = '';
        if ($protocol !== NULL) {
            $misc .= 'Sec-WebSocket-Protocol ' . $protocol . "\r\n";
        }

        $this->writeRaw('HTTP/1.1 101 ' . \PHPWebSocket::GetStringForErrorCode(101) . "\r\nServer: " . $this->_server->getServerIdentifier() . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . base64_encode($this->_rawToken) . "\r\n" . $misc . "\r\n", FALSE);

        $this->_accepted = TRUE;

    }

    /**
     * Denies the websocket connection, a HTTP error code has to be provided indicating what went wrong
     *
     * @param int $errCode
     */
    public function deny(int $errCode) {

        if ($this->isAccepted()) {
            throw new \Exception('Connection has already been accepted!');
        }

        $this->writeRaw('HTTP/1.1 ' . $errCode . ' ' . \PHPWebSocket::GetStringForErrorCode($errCode) . "\r\nServer: " . $this->_server->getServerIdentifier() . "\r\n\r\n", FALSE);
        $this->setCloseAfterWrite();

    }

    /**
     * Returns if the websocket connection has been accepted
     *
     * @return bool
     */
    public function isAccepted() : bool {
        return $this->_accepted;
    }

    /**
     * Returns the related websocket server
     *
     * @return \PHPWebSocket\Server
     */
    public function getServer() : \PHPWebSocket\Server {
        return $this->_server;
    }

    /**
     * Returns if we've received the handshake
     *
     * @return bool
     */
    public function hasHandshake() : bool {
        return $this->_hasHandshake;
    }

    /**
     * Returns the timestamp at which the connection was opened
     */
    public function getOpenedTimestamp() : float {
        return $this->_openedTimestamp;
    }

    /**
     * Returns if the frame we are writing should be masked
     *
     * @return bool
     */
    protected function _shouldMask() : bool {
        return FALSE;
    }

    /**
     * Returns the headers set during the http request
     *
     * @return array
     */
    public function getHeaders() : array {
        return $this->_headers;
    }

    /**
     * Returns the remote IP address of the client
     *
     * @return string|null
     */
    public function getRemoteIP() {
        return $this->_remoteIP;
    }

    /**
     * Returns the stream object for this connection
     *
     * @return resource
     */
    public function getStream() {
        return $this->_stream;
    }

    /**
     * Returns the index for this connection
     *
     * @return int
     */
    public function getIndex() : int {
        return $this->_index;
    }

    /**
     * Returns if our connection is open
     *
     * @return bool
     */
    public function isOpen() : bool {
        return is_resource($this->_stream);
    }

    /**
     * Simply closes the connection
     */
    public function close() {

        if (is_resource($this->_stream)) {
            fclose($this->_stream);
            $this->_stream = NULL;
        }

        $this->_server->removeConnection($this);

    }

    public function __toString() {

        $remoteIP = $this->getRemoteIP();

        return 'WSConnection #' . $this->_resourceIndex . ($remoteIP ? ' => ' . $remoteIP : '') . ' @ ' . $this->_server;
    }
}
