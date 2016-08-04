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

namespace PHPWebSocket\Server;
use \PHPWebSocket\Update;
use \PHPWebSocket\Framer;

class Connection {

    /**
     * The amount of bytes to read to complete our current frame
     * @var integer|NULL
     */
    protected $_currentFrameRemainingBytes = NULL;

    /**
     * The control messages ready to be send (Takes priority over the normal messages buffer)
     * @var string[]
     */
    protected $_controlMessagesBuffer = array();

    /**
     * If we've send the disconnect message to the remote
     * @var boolean
     */
    protected $_serverSendDisconnect = FALSE;

    /**
     * If we've recieved the disconnect message from the remote
     * @var boolean
     */
    protected $_clientSentDisconnect = FALSE;

    /**
     * If we should close the connection after our write buffer has been emptied
     * @var boolean
     */
    protected $_closeAfterWrite = FALSE;

    /**
     * The framed messages ready to be send
     * @var string[]
     */
    protected $_messagesBuffer = array();

    /**
     * The opcode of the current partial message
     * @var integer|NULL
     */
    protected $_partialMessageOpcode = NULL;

    /**
     * The partial message if the current message hasn't finished yet
     * @var string|NULL
     */
    protected $_partialMessage = NULL;

    /**
     * If we've finished the handshake
     * @var boolean
     */
    protected $_hasHandshake = FALSE;

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
     * The websocket object related to this connection
     * @var \PHPWebSocket\Server
     */
    protected $_websocket = NULL;

    /**
     * If the connection has been accepted
     * @var boolean
     */
    protected $_accepted = FALSE;

    /**
     * The remote IP
     * @var string
     */
    protected $_remoteIP = NULL;

    /**
     * The websocket token
     * @var string
     */
    protected $_rawToken = NULL;

    /**
     * The headers send during the handshake
     * @var array
     */
    protected $_headers = NULL;

    /**
     * The resource stream
     * @var resource
     */
    protected $_stream = NULL;

    /**
     * The connection's index in the connections array
     * @var integer
     */
    private $_index = NULL;

    public function __construct(\PHPWebSocket\Server $websocket, $stream, string $streamName, int $index) {

        $this->_openedTimestamp = microtime(TRUE);
        $this->_websocket = $websocket;
        $this->_remoteIP = parse_url($streamName, PHP_URL_HOST);
        $this->_stream = $stream;
        $this->_index = $index;

        if ($this->_websocket->usesSSL()) {
            stream_socket_enable_crypto($this->_stream, TRUE, STREAM_CRYPTO_METHOD_TLS_SERVER);
        }

        stream_set_timeout($this->_stream, 15);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

    }

    /**
     * Writes the current buffer to the connection
     * @return \PHPWebSocket\Update\Error|NULL
     */
    public function handleWrite() {

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

            $bytesWritten = fwrite($this->_stream, $this->_writeBuffer, min($this->_websocket->getWriteRate($this), $bytesToWrite));
            if ($bytesWritten === FALSE) {
                \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '    fwrite failed');
                return new Update\Error(Update\Error::C_WRITE, $this);
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
            $this->_websocket->removeConnection($this);
        }

        return NULL;
    }

    /**
     * Attempts to read from our connection
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleRead() : \Generator {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, __METHOD__);

        $readRate = $this->_websocket->getReadRate($this);
        $newData = fread($this->getStream(), min($this->_currentFrameRemainingBytes ?? $readRate, $readRate));
        if ($newData === FALSE) {
            yield new Update\Error(Update\Error::C_READ, $this);
            return;
        }

        $updates = array();
        if (strlen($newData) === 0) {

            if ($this->_clientSentDisconnect) {
                yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);
            } else {
                yield new Update\Error(Update\Error::C_READ_UNEXPECTED_DISCONNECT, $this);
            }

            $this->_websocket->removeConnection($this);

            return;

        } else {

            $hasHandshake = $this->hasHandshake();
            if (!$hasHandshake) {

                $headersEnd = strpos($newData, "\r\n\r\n");
                if ($headersEnd === FALSE) {

                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Handshake data hasn\'t finished yet, waiting..');

                    if ($this->_readBuffer === NULL) {
                        $this->_readBuffer = '';
                    }

                    $this->_readBuffer .= $newData;

                    if (strlen($this->_readBuffer) > \PHPWebSocket\Server::HANDSHAKE_MAXLENGTH) {
                        yield new Update\Error(Update\Error::C_READ_HANDSHAKETOLARGE, $this);
                    }

                    $this->_websocket->removeConnection($this);

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

                    $this->_messagesBuffer[] = $this->_websocket->getErrorPageForCode($responseCode);
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
     * Handles exceptional data reads
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleExceptional() : \Generator {
        return; // Maybe we'll need this someday
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
                break; // Frame isn't ready yet
            } elseif ($message === FALSE) {

                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR);
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                return;
            } else {

                $opcode = $headers[Framer::IND_OPCODE];
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

                        $this->sendDisconnect($code, $disconnectMessage); // Echo the disconnect
                        $this->setCloseAfterWrite();

                        yield new Update\Read(Update\Read::C_READ_DISCONNECT, $this);
                        $this->_clientSentDisconnect = TRUE;

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

        if (!empty($pongs) && !$this->isDisconnecting()) {

            foreach ($pongs as $pongPayload) {
                $this->write($pongPayload, \PHPWebSocket::OPCODE_PONG);
            }

        }

    }

    /**
     * Handles the handshake from the client and returns if the handshake was valid
     * @param  string $rawHandshake
     * @return boolean
     */
    protected function _doHandshake(string $rawHandshake, int &$responseCode) : bool {

        $headers = \PHPWebSocket::ParseHTTPHeaders($rawHandshake);

        $responseCode = 101;
        if (!isset($headers['get'])) {
            $responseCode = 405;
        } else if (!isset($headers['host'])) {
            $responseCode = 400;
        } elseif (!isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket') {
            $responseCode = 400;
        } elseif (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE) {
            $responseCode = 400;
        } elseif (!isset($headers['sec-websocket-key'])) {
            $responseCode = 400;
        } elseif (!isset($headers['sec-websocket-version']) || intval($headers['sec-websocket-version']) !== 13) {
            $responseCode = 426;
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
     * Splits the provided data into frames of the specified size and sends them to the client
     * @param string  $data
     * @param integer $opcode
     * @param integer $frameSize
     */
    public function writeMultiFramed(string $data, int $opcode = \PHPWebSocket::OPCODE_FRAME_TEXT, int $frameSize = 65535) {

        if ($opcode !== \PHPWebSocket::OPCODE_FRAME_TEXT && $opcode !== \PHPWebSocket::OPCODE_FRAME_BINARY) {
            throw new \Exception('Only OPCODE_FRAME_TEXT and OPCODE_FRAME_BINARY are supported in ' . __METHOD__);
        }
        if ($frameSize < 1) {
            throw new \Exception('FrameSize should be at least 1');
        }

        $frames = str_split($data, $frameSize);
        end($frames);
        $lastKey = key($frames);

        foreach ($frames as $key => $frame) {
            $this->write($frame, $opcode, $key === $lastKey);
        }

    }

    /**
     * Queues a string to be written to the client
     * @param string  $data
     * @param integer $opcode
     * @param boolean $isFinal
     */
    public function write(string $data, int $opcode = \PHPWebSocket::OPCODE_FRAME_TEXT, bool $isFinal = TRUE) {

        $frame = Framer::Frame($data, FALSE, $opcode, $isFinal);

        if (\PHPWebSocket::IsControlOpcode($opcode)) {
            $this->_controlMessagesBuffer[] = $frame;
        } else {
            $this->_messagesBuffer[] = $frame;
        }

    }

    /**
     * Sends a disconnect message to the client, note: this doesn't close the connection, use setCloseAfterWrite(); or close the connection manually
     * @param integer $code
     * @param string  $reason
     */
    public function sendDisconnect(int $code, string $reason = '') {

        $this->_serverSendDisconnect = TRUE;
        $this->write(pack('n', $code) . $reason, \PHPWebSocket::OPCODE_CLOSE_CONNECTION);

    }

    /**
     * Accepts the connection
     * @param  string|NULL $protocol The accepted protocol
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

        $this->_messagesBuffer[] = 'HTTP/1.1 101 ' . \PHPWebSocket::GetStringForErrorCode(101) . "\n\rServer: PHPWebSocket/" . \PHPWebSocket::Version() . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . base64_encode($this->_rawToken) . "\r\n" . $misc . "\r\n";

        $this->_accepted = TRUE;

    }

    /**
     * Denies the websocket connection
     * @param integer $errCode
     */
    public function deny(int $errCode) {

        if ($this->isAccepted()) {
            throw new \Exception('Connection has already been accepted!');
        }

        $this->_messagesBuffer[] = 'HTTP/1.1 ' . $errCode . ' ' . \PHPWebSocket::GetStringForErrorCode($errCode) . "\n\rServer: PHPWebSocket/" . \PHPWebSocket::Version() . "\r\n\r\n";
        $this->setCloseAfterWrite();

    }

    /**
     * Checks if the client is in error by sending us one of the RSV bits
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
     * Returns TRUE if we are disconnecting
     * @return boolean
     */
    public function isDisconnecting() : bool {
        return $this->_clientSentDisconnect || $this->_serverSendDisconnect;
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
     * Returns if the websocket connection has been accepted
     * @return boolean
     */
    public function isAccepted() : bool {
        return $this->_accepted;
    }

    /**
     * Returns if we've received the handshake
     * @return boolean
     */
    public function hasHandshake() : bool {
        return $this->_hasHandshake;
    }

    /**
     * Sets that we should close the connection after all our writes have finished
     */
    public function setCloseAfterWrite() {
        $this->_closeAfterWrite = TRUE;
    }

    /**
     * Returns if we have (partial)frames ready to be send
     * @return boolean
     */
    public function isWriteBufferEmpty() : bool {
        return empty($this->_controlMessagesBuffer) && empty($this->_messagesBuffer) && empty($this->_writeBuffer);
    }

    /**
     * Returns the timestamp at which the connection was opened
     */
    public function getOpenedTimestamp() : float {
        return $this->_openedTimestamp;
    }

    /**
     * Returns the remote IP address of the client
     */
    public function getRemoteIP() : string {
        return $this->_remoteIP;
    }

    /**
     * Returns the stream object for this connection
     * @return resource
     */
    public function getStream() {
        return $this->_stream;
    }

    /**
     * Returns the index for this connection
     * @return integer
     */
    public function getIndex() : int {
        return $this->_index;
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

    public function __toString() {
        return 'connection #' . $this->getIndex() . ' => ' . $this->getRemoteIP();
    }

    public function __destruct() {
        $this->close();
    }

}
