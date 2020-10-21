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

namespace PHPWebSockets\Server;

use PHPWebSockets\{AConnection, Server, Update};
use Psr\Log\LogLevel;

class Connection extends AConnection {

    /**
     * The time in seconds in which the client has to send its handshake
     *
     * @var float
     */
    protected $_acceptTimeout = 5.0;

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
     * @var \PHPWebSockets\Server|null
     */
    protected $_server = NULL;

    /**
     * The connection's index in the connections array
     *
     * @var int
     */
    private $_index = NULL;

    public function __construct(Server $server, $stream, string $streamName, int $index) {

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('The $stream argument has to be a resource!');
        }

        $this->_remoteIP = parse_url($streamName, PHP_URL_HOST);
        $this->_server = $server;
        $this->_stream = $stream;
        $this->_index = $index;

        // Inherit the logger from the server
        $serverLogger = $server->getLogger();
        if ($serverLogger !== \PHPWebSockets::GetLogger()) {
            $this->setLogger($serverLogger);
        }

        $this->_resourceIndex = (int) $this->_stream;

        // The crypto enable HAS to happen before disabling blocking mode
        if ($server->usesCrypto()) {
            stream_socket_enable_crypto($this->_stream, TRUE, STREAM_CRYPTO_METHOD_TLS_SERVER);
        }

        $this->_afterOpen();

    }

    /**
     * Attempts to read from our connection
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function handleRead() : \Generator {

        $this->_log(LogLevel::DEBUG, __METHOD__);

        $readRate = $this->getReadRate();
        $newData = @fread($this->getStream(), min($this->_currentFrameRemainingBytes ?? $readRate, $readRate));
        if ($newData === FALSE) {
            yield new Update\Error(Update\Error::C_READ, $this);

            return;
        }

        if (strlen($newData) === 0) {

            $this->_isClosed = TRUE;

            if (!$this->hasHandshake()) {
                yield new Update\Error(Update\Error::C_READ_DISCONNECT_DURING_HANDSHAKE, $this);
            } elseif ($this->_remoteSentDisconnect && $this->_weSentDisconnect) {
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

                    $this->_log(LogLevel::DEBUG, 'Handshake data hasn\'t finished yet, waiting..');

                    if ($this->_readBuffer === NULL) {
                        $this->_readBuffer = '';
                    }

                    $this->_readBuffer .= $newData;

                    if (strlen($this->_readBuffer) > $this->getMaxHandshakeLength()) {

                        $this->writeRaw($this->_server->getErrorPageForCode(431), FALSE); // Request Header Fields Too Large
                        $this->setCloseAfterWrite();

                        yield new Update\Error(Update\Error::C_READ_HANDSHAKE_TO_LARGE, $this);

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
                    yield new Update\Read(Update\Read::C_NEW_CONNECTION, $this);
                } else {

                    $this->writeRaw($this->_server->getErrorPageForCode($responseCode), FALSE);
                    $this->setCloseAfterWrite();

                    yield new Update\Error(Update\Error::C_READ_HANDSHAKE_FAILURE, $this);

                }

                $hasHandshake = $this->hasHandshake();

            }

            if ($hasHandshake) {
                yield from $this->_handlePacket($newData);
            }

        }

    }

    /**
     * {@inheritdoc}
     */
    public function beforeStreamSelect() : \Generator {

        yield from parent::beforeStreamSelect();

        if (!$this->isAccepted() && $this->hasHandshake() && $this->getOpenedTimestamp() + $this->getAcceptTimeout() < time()) {

            yield new Update\Error(Update\Error::C_ACCEPT_TIMEOUT_PASSED, $this);
            $this->deny(504); // Gateway Timeout

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

        $headers = \PHPWebSockets::ParseHTTPHeaders($rawHandshake);

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

        $this->_parseHeaders();

        if ($responseCode >= 300) {
            return FALSE;
        }

        $this->_hasHandshake = TRUE;

        $hash = sha1($headers['sec-websocket-key'] . \PHPWebSockets::WEBSOCKET_GUID);
        $this->_rawToken = '';
        for ($i = 0; $i < 20; $i++) {
            $this->_rawToken .= chr(hexdec(substr($hash, $i * 2, 2)));
        }

        return TRUE;
    }

    /**
     * @return void
     */
    protected function _parseHeaders() : void {

        if ($this->_server && $this->_server->getTrustForwardedHeaders()) {

            $headers = $this->getHeaders();

            $realIP = $headers['x-real-ip'] ?? NULL;
            if ($realIP) {
                $this->_remoteIP = $realIP;
            } else {

                /*
                 * X-Forwarded-For is a comma separated list of proxies, the first entry is the client's IP
                 */

                $forwardedForParts = explode(',', $headers['x-forwarded-for'] ?? '');
                $forwardedFor = reset($forwardedForParts);
                if ($forwardedFor) {
                    $this->_remoteIP = $forwardedFor;
                }

            }

        }

    }

    /**
     * Accepts the connection
     *
     * @param string|null $protocol The accepted protocol
     *
     * @return void
     */
    public function accept(string $protocol = NULL) : void {

        if ($this->isAccepted()) {
            throw new \LogicException('Connection has already been accepted!');
        }

        $misc = '';
        if ($protocol !== NULL) {
            $misc .= 'Sec-WebSocket-Protocol ' . $protocol . "\r\n";
        }

        $this->writeRaw('HTTP/1.1 101 ' . \PHPWebSockets::GetStringForStatusCode(101) . "\r\nServer: " . $this->_server->getServerIdentifier() . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . base64_encode($this->_rawToken) . "\r\n" . $misc . "\r\n", FALSE);

        $this->_accepted = TRUE;

    }

    /**
     * Denies the websocket connection, a HTTP error code has to be provided indicating what went wrong
     *
     * @param int $errCode
     *
     * @return void
     */
    public function deny(int $errCode) : void {

        if ($this->isAccepted()) {
            throw new \LogicException('Connection has already been accepted!');
        }

        $this->writeRaw('HTTP/1.1 ' . $errCode . ' ' . \PHPWebSockets::GetStringForStatusCode($errCode) . "\r\nServer: " . $this->_server->getServerIdentifier() . "\r\n\r\n", FALSE);
        $this->setCloseAfterWrite();

    }

    /**
     * Detaches this connection from its server
     *
     * @return void
     */
    public function detach() : void {

        if (!$this->isAccepted()) {
            throw new \LogicException('Connections can only be detached after it has been accepted');
        }

        $this->_server->removeConnection($this, FALSE);
        $this->_server = NULL;

    }

    /**
     * Sets the time in seconds in which the client has to send its handshake
     *
     * @param float $timeout
     *
     * @return void
     */
    public function setAcceptTimeout(float $timeout) : void {
        $this->_acceptTimeout = $timeout;
    }

    /**
     * Returns the time in seconds in which the client has to send its handshake
     *
     * @return float
     */
    public function getAcceptTimeout() : float {
        return $this->_acceptTimeout;
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
     * @return \PHPWebSockets\Server|null
     */
    public function getServer() : ?Server {
        return $this->_server;
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
     * Returns the headers set during the http request, this can be empty if called before the handshake has been completed
     *
     * @return array
     */
    public function getHeaders() : array {
        return $this->_headers ?: [];
    }

    /**
     * Returns the remote IP address of the client
     *
     * @return string|null
     */
    public function getRemoteIP() : ?string {
        return $this->_remoteIP;
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
     * {@inheritdoc}
     */
    public function close() : void {

        parent::close();

        if ($this->_shouldReportClose && !$this->isAccepted()) {

            /*
             * Don't report close if we've never been accepted
             */

            $this->_shouldReportClose = FALSE;

        }

        if ($this->_server !== NULL) {

            if (!$this->_shouldReportClose) {

                $this->_log(LogLevel::DEBUG, 'Not reporting, remove now');
                $this->_server->removeConnection($this);

            } else {
                $this->_log(LogLevel::DEBUG, 'Going to report later, not removing');
            }

        } else {
            $this->_log(LogLevel::DEBUG, 'No server, not removing');
        }

    }

    /**
     * {@inheritdoc}
     */
    protected function _afterReportClose() : void {

        if ($this->_server !== NULL) {

            $this->_log(LogLevel::DEBUG, 'We reported close, removing from server');
            $this->_server->removeConnection($this);

        }

    }

    public function __toString() {

        $remoteIP = $this->getRemoteIP();
        $tag = $this->getTag();

        return 'WSConnection #' . $this->_resourceIndex . ($remoteIP ? ' => ' . $remoteIP : '') . ($tag === NULL ? '' : ' (Tag: ' . $tag . ')') . ($this->_server !== NULL ? ' @ ' . $this->_server : '');
    }
}
