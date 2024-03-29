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

namespace PHPWebSockets;

use Psr\Log\{LogLevel, LoggerInterface};

class Client extends AConnection {

    /**
     * The last error code received from the stream
     *
     * @var int|null
     */
    protected $_streamLastErrorCode = NULL;

    /**
     * If the handshake has been accepted by the server
     *
     * @var bool
     */
    protected $_handshakeAccepted = FALSE;

    /**
     * If we are currently trying to connect async
     *
     * @var bool
     */
    protected $_isConnectingAsync = FALSE;

    /**
     * The last error received from the stream
     *
     * @var string|null
     */
    protected $_streamLastError = NULL;

    /**
     * If we should send our frames masked
     *
     * Note: Setting this to FALSE is officially not supported by the websocket RFC, but can improve performance
     *
     * @see https://tools.ietf.org/html/rfc6455#section-5.3
     *
     * @var bool
     */
    protected $_shouldMask = TRUE;

    /**
     * @var string|null
     */
    protected $_userAgent = NULL;

    /**
     * The headers send back from the server when the handshake was accepted
     *
     * @var array|null
     */
    protected $_headers = NULL;

    /**
     * The remote address we are connecting to
     *
     * @var string|null
     */
    protected $_address = NULL;

    /**
     * The path used in the HTTP request
     *
     * @var string|null
     */
    protected $_path = NULL;

    public function __construct(LoggerInterface $logger = NULL) {

        if ($logger) {
            $this->setLogger($logger);
        }

    }

    /**
     * Connects to the provided resource
     *
     * @param resource $resource
     * @param string   $path
     *
     * @return bool
     */
    public function connectToResource($resource, string $path = '/') : bool {

        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Argument 1 is not a resource!');
        }

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $this->_address = @stream_get_meta_data($resource)['uri'] ?? NULL;
        $this->_stream = $resource;
        $this->_path = $path;

        $this->_afterOpen();

        return TRUE;
    }

    /**
     * Attempts to connect to a websocket server
     *
     * @param string $address
     * @param string $path
     * @param array  $streamContext
     * @param bool   $async
     *
     * @return bool
     */
    public function connect(string $address, string $path = '/', array $streamContext = [], bool $async = FALSE) : bool {

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $this->_isConnectingAsync = $async;

        $flags = ($async ? STREAM_CLIENT_ASYNC_CONNECT : STREAM_CLIENT_CONNECT);

        $this->_stream = @stream_socket_client($address, $this->_streamLastErrorCode, $this->_streamLastError, $this->getConnectTimeout(), $flags, stream_context_create($streamContext));
        if ($this->_stream === FALSE) {
            return FALSE;
        }

        $this->_address = $address;
        $this->_path = $path;

        $this->_afterOpen();

        return TRUE;
    }

    /**
     * {@inheritdoc}
     */
    protected function _afterOpen() : void {

        parent::_afterOpen();

        $this->_resourceIndex = (int) $this->getStream();
        $addressInfo = parse_url($this->getAddress());

        $host = $addressInfo['host'] . (isset($addressInfo['port']) ? ':' . $addressInfo['port'] : '');

        $headerParts = [
            'GET ' . $this->getPath() . ' HTTP/1.1',
            'Host: ' . $host,
            'User-Agent: ' . $this->getUserAgent(),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . base64_encode(\PHPWebSockets::RandomString(16)),
            'Sec-WebSocket-Version: 13',
        ];

        $this->writeRaw(implode("\r\n", $headerParts) . "\r\n\r\n", FALSE);

    }

    /**
     * Returns the code of the last error that occurred
     *
     * @return int|null
     */
    public function getLastErrorCode() : ?int {
        return $this->_streamLastErrorCode;
    }

    /**
     * Returns the last error that occurred
     *
     * @return string|null
     */
    public function getLastError() : ?string {
        return $this->_streamLastError;
    }

    /**
     * Checks for updates for this client
     *
     * @param float|null $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function update(?float $timeout) : \Generator {
        yield from \PHPWebSockets::MultiUpdate([$this], $timeout);
    }

    /**
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function handleRead() : \Generator {

        $this->_log(LogLevel::DEBUG, __METHOD__);

        $readRate = $this->getReadRate();
        $newData = fread($this->getStream(), min($this->_currentFrameRemainingBytes ?? $readRate, $readRate));
        if ($newData === FALSE) {
            yield new Update\Error(Update\Error::C_READ, $this);

            return;
        }

        if (strlen($newData) === 0) {

            if (!feof($this->getStream())) {
                $this->_log(LogLevel::DEBUG, 'Read length of 0, ignoring');
                return;
            }

            $this->_log(LogLevel::DEBUG, 'FEOF returns true and no more bytes to read, socket is closed');

            $this->_isClosed = TRUE;

            if ($this->_remoteSentDisconnect && $this->_weSentDisconnect) {
                yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);
            } elseif ($this->_isConnectingAsync) {
                yield new Update\Error(Update\Error::C_ASYNC_CONNECT_FAILED, $this);
            } else {
                yield new Update\Error(Update\Error::C_READ_UNEXPECTED_DISCONNECT, $this);
            }

            $this->close();

            return;
        }

        // If we've received data we can be sure we're connected
        $this->_isConnectingAsync = FALSE;

        $handshakeAccepted = $this->handshakeAccepted();
        if (!$handshakeAccepted) {

            $headersEnd = strpos($newData, "\r\n\r\n");
            if ($headersEnd === FALSE) {

                $this->_log(LogLevel::DEBUG, 'Handshake data didn\'t finished yet, waiting..');

                if ($this->_readBuffer === NULL) {
                    $this->_readBuffer = '';
                }

                $this->_readBuffer .= $newData;

                if (strlen($this->_readBuffer) > $this->getMaxHandshakeLength()) {

                    yield new Update\Error(Update\Error::C_READ_HANDSHAKE_TO_LARGE, $this);
                    $this->close();

                }

                return; // Still waiting for headers
            }

            if ($this->_readBuffer !== NULL) {

                $newData = $this->_readBuffer . $newData;
                $this->_readBuffer = NULL;

            }

            $rawHandshake = substr($newData, 0, $headersEnd);

            if (strlen($newData) > strlen($rawHandshake)) { // Place all data that came after the header back into the buffer
                $newData = substr($newData, $headersEnd + 4);
            }

            $this->_headers = \PHPWebSockets::ParseHTTPHeaders($rawHandshake);
            if (($this->_headers['status-code'] ?? NULL) === 101) {

                $this->_handshakeAccepted = TRUE;
                $this->_hasHandshake = TRUE;

                yield new Update\Read(Update\Read::C_CONNECTION_ACCEPTED, $this);
            } else {

                $this->close();

                yield new Update\Read(Update\Read::C_CONNECTION_DENIED, $this);

            }

            $handshakeAccepted = $this->handshakeAccepted();

        }

        if ($handshakeAccepted) {
            yield from $this->_handlePacket($newData);
        }

    }

    /**
     * Sets that we should close the connection after all our writes have finished
     *
     * @return bool
     */
    public function handshakeAccepted() : bool {
        return $this->_handshakeAccepted;
    }

    /**
     * Returns the user agent string that is reported to the server that we are connecting to, if set to NULL the default value is used
     *
     * @param string|null $userAgent
     *
     * @return void
     */
    public function setUserAgent(?string $userAgent) : void {
        $this->_userAgent = $userAgent;
    }

    /**
     * Returns the user agent string that is reported to the server that we are connecting to
     *
     * @return string
     */
    public function getUserAgent() : string {
        return $this->_userAgent ?? 'PHPWebSockets/' . \PHPWebSockets::Version();
    }

    /**
     * If we should send our frames masked
     *
     * Note: Setting this to FALSE is officially not supported by the websocket RFC, but can improve performance when communicating with servers that support this
     *
     * @see https://tools.ietf.org/html/rfc6455#section-5.3
     *
     * @param bool $mask
     *
     * @return void
     */
    public function setMasksPayload(bool $mask) : void {
        $this->_shouldMask = $mask;
    }

    /**
     * Returns if the frame we are writing should be masked
     *
     * @return bool
     */
    protected function _shouldMask() : bool {
        return $this->_shouldMask;
    }

    /**
     * Returns the timeout in seconds before the connection attempt stops
     *
     * @return float
     */
    public function getConnectTimeout() : float {
        return (float) (ini_get('default_socket_timeout') ?: 1.0);
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
     * Returns the address that we connected to
     *
     * @return string|null
     */
    public function getAddress() : ?string {
        return $this->_address;
    }

    /**
     * Returns the path send in the HTTP request
     *
     * @return string|null
     */
    public function getPath() : ?string {
        return $this->_path;
    }

    public function __toString() {

        $tag = $this->getTag();

        return 'WSClient #' . $this->_resourceIndex . ($tag === NULL ? '' : ' (Tag: ' . $tag . ')');
    }
}
