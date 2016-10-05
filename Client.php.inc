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

class Client implements \PHPWebSocket\IStreamContainer {

    use \PHPWebSocket\TStreamContainerDefaults;

    /**
     * The amount of bytes to read to complete our current frame
     * @var integer|NULL
     */
    protected $_currentFrameRemainingBytes = NULL;

    /**
     * If we've initiated the disconnect
     * @var boolean
     */
    protected $_didWeInitiateDisconnect = FALSE;

    /**
     * The control messages ready to be send (Takes priority over the normal messages buffer)
     * @var string[]
     */
    protected $_controlMessagesBuffer = array();

    /**
     * If we've received the disconnect message from the remote
     * @var boolean
     */
    protected $_serverSentDisconnect = FALSE;

    /**
     * If we've send the disconnect message to the remote
     * @var boolean
     */
    protected $_clientSendDisconnect = FALSE;

    /**
     * The opcode of the current partial message
     * @var integer|NULL
     */
    protected $_partialMessageOpcode = NULL;

    /**
     * The last error code received from the stream
     * @var integer|NULL
     */
    protected $_streamLastErrorCode = NULL;

    /**
     * The maximum size the handshake can become
     * @var integer
     */
    protected $_maxHandshakeLength = 8192;

    /**
     * If the handshake has been accepted by the server
     * @var boolean
     */
    protected $_handshakeAccepted = FALSE;

    /**
     * The last error received from the stream
     * @var string|NULL
     */
    protected $_streamLastError = NULL;

    /**
     * If we should close the connection after our write buffer has been emptied
     * @var boolean
     */
    protected $_closeAfterWrite = FALSE;

    /**
     * The partial message if the current message hasn't finished yet
     * @var string|NULL
     */
    protected $_partialMessage = NULL;

    /**
     * The framed messages ready to be send
     * @var string[]
     */
    protected $_messagesBuffer = array();

    /**
     * The write buffer
     * @var string|NULL
     */
    protected $_writeBuffer = NULL;

    /**
     * The read buffer
     * @var string|NULL
     */
    protected $_readBuffer = NULL;

    /**
     * If the RSV1 property is allowed on this connection
     * @var boolean
     */
    protected $_allowRSV1 = FALSE;

    /**
     * If the RSV2 property is allowed on this connection
     * @var boolean
     */
    protected $_allowRSV2 = FALSE;

    /**
     * If the RSV3 property is allowed on this connection
     * @var boolean
     */
    protected $_allowRSV3 = FALSE;

    /**
     * The amount of bytes we write per cycle
     * @var integer
     */
    protected $_writeRate = 16384;

    /**
     * The amount of bytes we read per cycle
     * @var integer
     */
    protected $_readRate = 16384;

    /**
     * The headers send back from the server when the handshake was accepted
     * @var array|NULL
     */
    protected $_headers = NULL;

    /**
     * The stream connection
     * @var null
     */
    protected $_stream = NULL;

    /**
     * Connects to the provided resource
     * @param  resource $resource
     * @return boolean
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function connectToResource($resource, string $path = '/') {

        if (!is_resource($resource)) {
            throw \InvalidArgumentException('Argument is not a resource!');
        }

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $this->_stream = $resource;

        $this->_afterOpen($path, 'localhost');

        return TRUE;
    }

    /**
     * Attempts to connect to a websocket server
     * @param  string  $address
     * @param  integer $port
     * @param  string  $path
     * @param  boolean $useSSL
     * @param  boolean $allowSelfSigned
     * @return boolean
     * @throws \LogicException
     */
    public function connect(string $address, int $port = 80, string $path = '/', bool $useSSL = FALSE, bool $allowSelfSigned = FALSE) {

        if ($this->isOpen()) {
            throw new \LogicException('The connection is already open!');
        }

        $context = stream_context_create();
        if ($useSSL) {
            stream_context_set_option($context, 'ssl', 'allow_self_signed', $allowSelfSigned);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

            $port = ($port ?: ($useSSL ? 443 : 80));
            $protocol = ($useSSL ? 'tls' : 'tcp');

            $address = $protocol . '://' . $address . ':' . $port;

        }

        $this->_stream = @stream_socket_client($address, $this->_streamLastErrorCode, $this->_streamLastError, intval(ini_get('default_socket_timeout')), STREAM_CLIENT_CONNECT, $context);
        if ($this->_stream === FALSE) {
            return FALSE;
        }

        $this->_afterOpen($path, $address);

        return TRUE;
    }

    /**
     * Sets the stream settings and appends the HTTP headers to the buffer
     * @param string $path
     * @param string $address
     */
    protected function _afterOpen(string $path, string $address) {

        stream_set_timeout($this->_stream, 15);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

        $headerParts = array(
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . $address,
            'User-Agent: ' . $this->getUserAgent(),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . base64_encode(\PHPWebSocket::RandomString()),
            'Sec-WebSocket-Version: 13'
        );

        $this->_messagesBuffer[] = implode("\r\n", $headerParts) . "\r\n\r\n";

    }

    /**
     * Returns the code of the last error that occured
     * @return integer|NULL
     */
    public function getLastErrorCode() {
        return $this->_streamLastErrorCode;
    }

    /**
     * Returns the last error that occured
     * @return string|NULL
     */
    public function getLastError() {
        return $this->_streamLastError;
    }

    /**
     * Returns if we have (partial)frames ready to be send
     * @return boolean
     */
    public function isWriteBufferEmpty() : bool {
        return empty($this->_controlMessagesBuffer) && empty($this->_messagesBuffer) && empty($this->_writeBuffer);
    }

    /**
     * Returns the stream resource for this client
     * @return resource|NULL
     */
    public function getStream() {
        return $this->_stream;
    }

    /**
     * Checks for updates for this client
     * @param  float|NULL $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     * @return \PHPWebSocket\AUpdate[]
     * @throws \Exception
     */
    public function update(float $timeout = NULL) : \Generator {
        yield from \PHPWebSocket::MultiUpdate(array($this), $timeout);
    }

    /**
     * In here we attempt to find frames and unmask them, returns finished messages if available
     * @param string $newData
     * @return \PHPWebSocket\AUpdate[]
     */
    protected function _handlePacket(string $newData) : \Generator {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, __METHOD__);

        if ($this->_readBuffer === NULL) {
            $this->_readBuffer = $newData;
        } else {
            $this->_readBuffer .= $newData;
        }

        $orgBuffer = $this->_readBuffer;
        $numBytes = strlen($this->_readBuffer);
        $framePos = 0;
        $pongs = array();

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Handling packet, current buffer size: ' . strlen($this->_readBuffer));

        while ($framePos < $numBytes) {

            $headers = Framer::GetFrameHeaders($this->_readBuffer);
            if ($headers === NULL) { // Incomplete headers, probably due to a partial read
                break;
            }

            if (!$this->_checkRSVBits($headers)) {

                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR, 'Unexpected RSV bit set');
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_RVSBIT_SET, $this);

                return;
            }

            $frameSize = $headers[Framer::IND_LENGTH] + $headers[Framer::IND_PAYLOADOFFSET];
            if ($numBytes < $frameSize) {
                $this->_currentFrameRemainingBytes = $frameSize - $numBytes;
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Setting next read size to ' . $this->_currentFrameRemainingBytes);
                break;
            }

            $this->_currentFrameRemainingBytes = NULL;

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Expecting frame of length ' . $frameSize);

            $frame = substr($orgBuffer, $framePos, $frameSize);
            $message = Framer::GetFramePayload($frame, $headers);
            if ($message === NULL) {
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Framer returned NULL');
                break; // Frame isn't ready yet
            } elseif ($message === FALSE) {

                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR);
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                return;
            } else {

                $opcode = $headers[Framer::IND_OPCODE];
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Got payload, opcode: ' . $opcode);
                switch ($opcode) {
                    case \PHPWebSocket::OPCODE_CONTINUE:

                        if ($this->_partialMessage === NULL) {

                            $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR, 'Got OPCODE_CONTINUE but no frame that could be continued');
                            $this->setCloseAfterWrite();

                            yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                            return;
                        }

                    // Fall through intended
                    case \PHPWebSocket::OPCODE_FRAME_TEXT:
                    case \PHPWebSocket::OPCODE_FRAME_BINARY:

                        if ($opcode === \PHPWebSocket::OPCODE_CONTINUE) {
                            $this->_partialMessage .= $message;
                        } elseif ($this->_partialMessage !== NULL) {

                            $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR, 'Got new frame without completing the previous');
                            $this->setCloseAfterWrite();

                            yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                            return;

                        } else {

                            $this->_partialMessageOpcode = $opcode;
                            $this->_partialMessage = $message;

                        }

                        if ($headers[Framer::IND_FIN]) {

                            if ($this->_partialMessageOpcode === \PHPWebSocket::OPCODE_FRAME_TEXT && !preg_match('//u', $this->_partialMessage)) {

                                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_INVALID_PAYLOAD, 'Could not decode text frame as UTF-8');
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                                return;
                            }

                            yield new Update\Read(Update\Read::C_READ, $this, $this->_partialMessageOpcode, $this->_partialMessage);

                            $this->_partialMessageOpcode = NULL;
                            $this->_partialMessage = NULL;

                        } else {
                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'IND_FIN not TRUE');
                        }

                        break;
                    case \PHPWebSocket::OPCODE_CLOSE_CONNECTION:

                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Got disconnect');

                        $disconnectMessage = '';
                        $code = \PHPWebSocket::CLOSECODE_NORMAL;

                        if (strlen($message) > 1) {

                            $code = unpack('n', substr($message, 0, 2))[1]; // Send back the same disconnect code if provided
                            if (!\PHPWebSocket::IsValidCloseCode($code)) {

                                $disconnectMessage = 'Invalid close code provided: ' . $code;
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOLERROR;

                            } elseif (!preg_match('//u', substr($message, 2))) {

                                $disconnectMessage = 'Received Non-UTF8 close frame payload';
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOLERROR;

                            } else {
                                $disconnectMessage = substr($message, 2);
                            }

                        }

                        $this->_serverSentDisconnect = TRUE;
                        yield new Update\Read(Update\Read::C_READ_DISCONNECT, $this, $opcode, $message);

                        if ($this->_didWeInitiateDisconnect) {

                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '  We initiated the disconnect, close the connection');

                            $this->close();
                            yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);

                        } elseif (!$this->_clientSendDisconnect) {

                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '  Remote initiated the disconnect, echo disconnect');

                            $this->sendDisconnect($code, $disconnectMessage); // Echo the disconnect
                            $this->setCloseAfterWrite();

                        }

                        break;
                    case \PHPWebSocket::OPCODE_PING:

                        $pingPayload = (is_string($message) ? $message : '');

                        yield new Update\Read(Update\Read::C_PING, $this, $opcode, $pingPayload);
                        $pongs[] = $pingPayload;

                        break;
                    case \PHPWebSocket::OPCODE_PONG:
                        // No action
                        break;
                    default:
                        throw new \Exception('Got unknown opcode from framer!');
                }

            }

            $framePos += $frameSize;

            $this->_readBuffer = substr($orgBuffer, $framePos);

        }

        if (!empty($pongs)) {

            foreach ($pongs as $pongPayload) {
                $this->write($pongPayload, \PHPWebSocket::OPCODE_PONG);
            }

        }

    }

    /**
     * Attempts to read from our connection
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

        $updates = array();
        if (strlen($newData) === 0) {

            if ($this->_serverSentDisconnect) {
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
                if ($this->_headers['status-code'] === 101) {
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
     * Writes the current buffer to the connection
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleWrite() : \Generator {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, __METHOD__);

        if ($this->_writeBuffer !== NULL) { // If our current message hasn't finished yet
            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Resuming write');
        } elseif (!empty($this->_controlMessagesBuffer)) { // Control frames take priority over normal frames

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Starting new write (Control frame)');
            $this->_writeBuffer = array_shift($this->_controlMessagesBuffer);

        } elseif (!empty($this->_messagesBuffer)) {

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Starting new write');
            $this->_writeBuffer = array_shift($this->_messagesBuffer);

        }

        if ($this->_writeBuffer !== NULL) {

            $bytesToWrite = strlen($this->_writeBuffer);

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '  Attempting to write ' . $bytesToWrite . ' bytes');

            $bytesWritten = fwrite($this->_stream, $this->_writeBuffer, min($this->getWriteRate($this), $bytesToWrite));
            if ($bytesWritten === FALSE) {
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '    fwrite failed');
                yield new Update\Error(Update\Error::C_WRITE, $this);
            } elseif ($bytesWritten === $bytesToWrite) {
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '    All bytes written');
                $this->_writeBuffer = NULL;
            } else {
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '    Written ' . $bytesWritten . ' bytes');
                $this->_writeBuffer = substr($this->_writeBuffer, $bytesWritten);
            }

        }

        if ($this->_closeAfterWrite && $this->isWriteBufferEmpty()) {
            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '      Close after write');
            $this->close($this);
        }

    }

    /**
     * Queues a string to be written to the client
     * @param string  $data
     * @param integer $opcode
     * @param boolean $isFinal
     */
    public function write(string $data, int $opcode = \PHPWebSocket::OPCODE_FRAME_TEXT, bool $isFinal = TRUE) {

        $frame = Framer::Frame($data, TRUE, $opcode, $isFinal);

        if (\PHPWebSocket::IsControlOpcode($opcode)) {
            $this->_controlMessagesBuffer[] = $frame;
        } else {
            $this->_messagesBuffer[] = $frame;
        }

    }

    /**
     * Sends a disconnect message to the server, note: this doesn't close the connection, use setCloseAfterWrite(); or close the connection manually
     * @param integer $code
     * @param string  $reason
     */
    public function sendDisconnect(int $code, string $reason = '') {

        if (!$this->_serverSentDisconnect) {
            $this->_didWeInitiateDisconnect = TRUE;
        }

        $this->_clientSendDisconnect = TRUE;

        $this->write(pack('n', $code) . $reason, \PHPWebSocket::OPCODE_CLOSE_CONNECTION);

    }

    /**
     * Checks if the server is in error by sending us one of the RSV bits
     * @param  array  $headers
     * @return boolean
     */
    protected function _checkRSVBits(array $headers) : bool {

        if (($headers[Framer::IND_RSV1] && !$this->_allowRSV1) || ($headers[Framer::IND_RSV2] && !$this->_allowRSV2) || ($headers[Framer::IND_RSV3] && !$this->_allowRSV3)) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Sets the maximum size for the handshake in bytes
     * @param integer $maxLength
     */
    public function setMaxHandshakeLength(int $maxLength) {
        $this->_maxHandshakeLength = $maxLength;
    }

    /**
     * Returns the maximum size for the handshake in bytes
     * @return integer
     */
    public function getMaxHandshakeLength() : int {
        return $this->_maxHandshakeLength;
    }

    /**
     * Sets that we should close the connection after all our writes have finished
     */
    public function setCloseAfterWrite() {
        $this->_closeAfterWrite = TRUE;
    }

    /**
     * Sets that we should close the connection after all our writes have finished
     * @return boolean
     */
    public function handshakeAccepted() : bool {
        return $this->_handshakeAccepted;
    }

    /**
     * Returns the user agent string that is reported to the server that we are connecting to
     * @return string
     */
    public function getUserAgent() : string {
        return 'PHPWebSocket/' . \PHPWebSocket::Version();
    }

    /**
     * Sets if we allow the RSV1 bit to be set
     * @param boolean $allow
     */
    public function setAllowRSV1(bool $allow) {
        $this->_allowRSV1 = $allow;
    }

    /**
     * Returns if we allow the RSV1 bit to be set
     * @return boolean
     */
    public function getAllowRSV1() : bool {
        return $this->_allowRSV1;
    }

    /**
     * Returns if we allow the RSV2 bit to be set
     * @param boolean $allow
     */
    public function setAllowRSV2(bool $allow) {
        $this->_allowRSV2 = $allow;
    }

    /**
     * Returns if we allow the RSV2 bit to be set
     * @return boolean
     */
    public function getAllowRSV2() : bool {
        return $this->_allowRSV2;
    }

    /**
     * Returns if we allow the RSV3 bit to be set
     * @param boolean $allow
     */
    public function setAllowRSV3(bool $allow) {
        $this->_allowRSV3 = $allow;
    }

    /**
     * Returns if we allow the RSV3 bit to be set
     * @return boolean
     */
    public function getAllowRSV3() : bool {
        return $this->_allowRSV3;
    }

    /**
     * Sets the maximum amount of bytes to write per cycle
     * @param integer $rate
     */
    public function setWriteRate(int $rate) {
        $this->_writeRate = $rate;
    }

    /**
     * Returns the maximum amount of bytes to write per cycle
     * @return integer
     */
    public function getWriteRate() : int {
        return $this->_writeRate;
    }

    /**
     * Sets the maximum amount of bytes to read per cycle
     * @param integer $rate
     */
    public function setReadRate(int $rate) {
        $this->_readRate = $rate;
    }

    /**
     * Returns the maximum amount of bytes to read per cycle
     * @return integer
     */
    public function getReadRate() : int {
        return $this->_readRate;
    }

    /**
     * Returns if our connection is open
     * @return boolean
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

    public function __destruct() {

        if ($this->isOpen()) {
            $this->close();
        }

    }

}
