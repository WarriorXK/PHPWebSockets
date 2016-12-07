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

class Client extends \PHPWebSocket\AConnection {

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
     * The last error received from the stream
     *
     * @var string|null
     */
    protected $_streamLastError = NULL;

    /**
     * The stream's resource index
     *
     * @var int|null
     */
    protected $_resourceIndex = NULL;

    /**
     * The headers send back from the server when the handshake was accepted
     *
     * @var array|null
     */
    protected $_headers = NULL;

    /**
     * The stream connection
     *
     * @var null
     */
    protected $_stream = NULL;

    /**
     * Connects to the provided resource
     *
     * @param resource $resource
     * @param string   $path
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     *
     * @return bool
     */
    public function connectToResource($resource, string $path = '/') {

        if (!is_resource($resource)) {
            throw \InvalidArgumentException('Argument is not a resource!');
        }

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $this->_stream = $resource;

        $this->_afterOpen((string) $resource, $path);

        return TRUE;
    }

    /**
     * Attempts to connect to a websocket server
     *
     * @param string $address
     * @param string $path
     *
     * @throws \LogicException
     *
     * @return bool
     */
    public function connect(string $address, string $path = '/') {

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $this->_stream = @stream_socket_client($address, $this->_streamLastErrorCode, $this->_streamLastError);
        if ($this->_stream === FALSE) {
            return FALSE;
        }

        $this->_afterOpen($address, $path);

        return TRUE;
    }

    /**
     * Sets the stream settings and appends the HTTP headers to the buffer
     *
     * @param string $address
     * @param string $path
     */
    protected function _afterOpen(string $address, string $path) {

        $this->_resourceIndex = (int) $this->getStream();

        stream_set_timeout($this->_stream, 15);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

        $headerParts = [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . $address,
            'User-Agent: ' . $this->getUserAgent(),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . base64_encode(\PHPWebSocket::RandomString()),
            'Sec-WebSocket-Version: 13',
        ];

        $this->writeRaw(implode("\r\n", $headerParts) . "\r\n\r\n", FALSE);

    }

    /**
     * Returns the code of the last error that occured
     *
     * @return int|null
     */
    public function getLastErrorCode() {
        return $this->_streamLastErrorCode;
    }

    /**
     * Returns the last error that occured
     *
     * @return string|null
     */
    public function getLastError() {
        return $this->_streamLastError;
    }

    /**
     * Returns the stream resource for this client
     *
     * @return resource|null
     */
    public function getStream() {
        return $this->_stream;
    }

    /**
     * Checks for updates for this client
     *
     * @param float|null $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @throws \Exception
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function update(float $timeout = NULL) : \Generator {
        yield from \PHPWebSocket::MultiUpdate([$this], $timeout);
    }

    /**
     * Attempts to read from our connection
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleRead() : \Generator {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, __METHOD__);

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

            $handshakeAccepted = $this->handshakeAccepted();
            if (!$handshakeAccepted) {

                $headersEnd = strpos($newData, "\r\n\r\n");
                if ($headersEnd === FALSE) {

                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Handshake data didn\'t finished yet, waiting..');

                    if ($this->_readBuffer === NULL) {
                        $this->_readBuffer = '';
                    }

                    $this->_readBuffer .= $newData;

                    if (strlen($this->_readBuffer) > $this->getMaxHandshakeLength()) {

                        yield new Update\Error(Update\Error::C_READ_HANDSHAKETOLARGE, $this);
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

                $this->_headers = \PHPWebSocket::ParseHTTPHeaders($rawHandshake);
                if (($this->_headers['status-code'] ?? NULL) === 101) {
                    $this->_handshakeAccepted = TRUE;
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
     * Returns the user agent string that is reported to the server that we are connecting to
     *
     * @return string
     */
    public function getUserAgent() : string {
        return 'PHPWebSocket/' . \PHPWebSocket::Version();
    }

    /**
     * Returns if the frame we are writing should be masked
     *
     * @return bool
     */
    protected function _shouldMask() : bool {
        return TRUE;
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

    }

    public function __toString() {
        return 'WSClient #' . $this->_resourceIndex;
    }
}
