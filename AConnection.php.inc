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

namespace PHPWebSocket;

abstract class AConnection implements IStreamContainer {

    use TStreamContainerDefaults;

    /**
     * The amount of bytes to read to complete our current frame
     *
     * @var int|null
     */
    protected $_currentFrameRemainingBytes = NULL;

    /**
     * The callable that gets called after the first message part has been read to get the optional stream to write to
     *
     * @var callable|null
     */
    protected $_newMessageStreamCallback = NULL;

    /**
     * If we've initiated the disconnect
     *
     * @var bool
     */
    protected $_weInitiateDisconnect = FALSE;

    /**
     * The opcode of the current partial message
     *
     * @var int|null
     */
    protected $_partialMessageOpcode = NULL;

    /**
     * If we've received the disconnect message from the remote
     *
     * @var bool
     */
    protected $_remoteSentDisconnect = FALSE;

    /**
     * The stream to write to instead of using _partialMessage
     *
     * @var resource|null
     */
    protected $_partialMessageStream = NULL;

    /**
     * The priority frames ready to be send (Takes priority over the normal frames buffer)
     *
     * @var string[]
     */
    protected $_priorityFramesBuffer = [];

    /**
     * The current state of the UTF8 validation
     * @var int
     */
    protected $_utfValidationState = \PHPWebSocket::UTF8_ACCEPT;

    /**
     * The maximum size the handshake can become
     *
     * @var int
     */
    protected $_maxHandshakeLength = 8192;

    /**
     * If we've sent the disconnect message to the remote
     *
     * @var bool
     */
    protected $_weSentDisconnect = FALSE;

    /**
     * If we should close the connection after our write buffer has been emptied
     *
     * @var bool
     */
    protected $_closeAfterWrite = FALSE;

    /**
     * The timestamp since when this connection has been opened
     *
     * @var int
     */
    protected $_openedTimestamp = NULL;

    /**
     * This array contains as key the index of the RSV bit and as value if it is allowed
     *
     * @var array
     */
    protected $_allowedRSVBits = [1 => FALSE, 2 => FALSE, 3 => FALSE];

    /**
     * The partial message if the current message hasn't finished yet
     *
     * @var string|null
     */
    protected $_partialMessage = NULL;

    /**
     * The frames ready to be send
     *
     * @var string[]
     */
    protected $_framesBuffer = [];

    /**
     * The write buffer
     *
     * @var string|null
     */
    protected $_writeBuffer = NULL;

    /**
     * The read buffer
     *
     * @var string|null
     */
    protected $_readBuffer = NULL;

    /**
     * The amount of bytes we write per cycle
     *
     * @var int
     */
    protected $_writeRate = 16384;

    /**
     * The amount of bytes we read per cycle
     *
     * @var int
     */
    protected $_readRate = 16384;

    /**
     * Sets the maximum size for the handshake in bytes
     *
     * @param int $maxLength
     */
    public function setMaxHandshakeLength(int $maxLength) {
        $this->_maxHandshakeLength = $maxLength;
    }

    /**
     * Returns the maximum size for the handshake in bytes
     *
     * @return int
     */
    public function getMaxHandshakeLength() : int {
        return $this->_maxHandshakeLength;
    }

    /**
     * Returns if we have (partial)frames ready to be send
     *
     * @return bool
     */
    public function isWriteBufferEmpty() : bool {
        return empty($this->_priorityFramesBuffer) && empty($this->_framesBuffer) && empty($this->_writeBuffer);
    }

    /**
     * Sets that we should close the connection after all our writes have finished
     */
    public function setCloseAfterWrite() {
        $this->_closeAfterWrite = TRUE;
    }

    /**
     * Should be called after the path and stream has been set to initialize
     */
    protected function _afterOpen() {

        $this->_openedTimestamp = microtime(TRUE);
        $stream = $this->getStream();

        stream_set_timeout($stream, 15);
        stream_set_blocking($stream, FALSE);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);

    }

    /**
     * In here we attempt to find frames and unmask them, returns finished messages if available
     *
     * @param string $newData
     *
     * @throws \UnexpectedValueException
     *
     * @return \Generator
     */
    protected function _handlePacket(string $newData) : \Generator {

        \PHPWebSocket::Log(LOG_DEBUG, __METHOD__);

        if ($this->_readBuffer === NULL) {
            $this->_readBuffer = $newData;
        } else {
            $this->_readBuffer .= $newData;
        }

        $orgBuffer = $this->_readBuffer;
        $numBytes = strlen($this->_readBuffer);
        $framePos = 0;
        $pongs = [];

        \PHPWebSocket::Log(LOG_DEBUG, 'Handling packet, current buffer size: ' . strlen($this->_readBuffer));

        while ($framePos < $numBytes) {

            $headers = Framer::GetFrameHeaders($this->_readBuffer);
            if ($headers === NULL) { // Incomplete headers, probably due to a partial read
                break;
            }

            if (!$this->_checkRSVBits($headers)) {

                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOL_ERROR, 'Unexpected RSV bit set');
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_RSVBIT_SET, $this);

                return;
            }

            $frameSize = $headers[Framer::IND_LENGTH] + $headers[Framer::IND_PAYLOAD_OFFSET];
            if ($numBytes < $frameSize) {
                $this->_currentFrameRemainingBytes = $frameSize - $numBytes;
                \PHPWebSocket::Log(LOG_DEBUG, 'Setting next read size to ' . $this->_currentFrameRemainingBytes);
                break;
            }

            $this->_currentFrameRemainingBytes = NULL;

            \PHPWebSocket::Log(LOG_DEBUG, 'Expecting frame of length ' . $frameSize);

            $frame = substr($orgBuffer, $framePos, $frameSize);
            $framePayload = Framer::GetFramePayload($frame, $headers);
            if ($framePayload === NULL) {
                break; // Frame isn't ready yet
            } elseif ($framePayload === FALSE) {

                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOL_ERROR);
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                return;
            } else {

                $opcode = $headers[Framer::IND_OPCODE];
                switch ($opcode) {
                    case \PHPWebSocket::OPCODE_CONTINUE:

                        if ($this->_partialMessage === NULL && $this->_partialMessageStream === NULL) {

                            $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOL_ERROR, 'Got OPCODE_CONTINUE but no frame that could be continued');
                            $this->setCloseAfterWrite();

                            yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                            return;
                        }

                    // Fall through intended
                    case \PHPWebSocket::OPCODE_FRAME_TEXT:
                    case \PHPWebSocket::OPCODE_FRAME_BINARY:

                        if (($this->_partialMessageOpcode ?: $opcode) === \PHPWebSocket::OPCODE_FRAME_TEXT) {

                            if (!\PHPWebSocket::ValidateUTF8($framePayload, $this->_utfValidationState) || ($headers[Framer::IND_FIN] && $this->_utfValidationState !== \PHPWebSocket::UTF8_ACCEPT)) {

                                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_INVALID_PAYLOAD, 'Could not decode text frame as UTF-8');
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                                return;
                            }

                        }

                        if ($opcode !== \PHPWebSocket::OPCODE_CONTINUE) {

                            if ($this->_partialMessage !== NULL || $this->_partialMessageStream !== NULL) {

                                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOL_ERROR, 'Got new frame without completing the previous');
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                                return;
                            }

                            $newMessageStream = $this->_getStreamForNewMessage($headers);
                            if ($newMessageStream === FALSE) {

                                $this->sendDisconnect(\PHPWebSocket::CLOSECODE_UNSUPPORTED_PAYLOAD);
                                $this->setCloseAfterWrite();

                                return;
                            }

                            $this->_partialMessageOpcode = $opcode;

                            if (is_resource($newMessageStream)) {

                                $this->_partialMessageStream = $newMessageStream;
                                $this->_partialMessage = NULL;

                            } else {

                                $this->_partialMessageStream = NULL;
                                $this->_partialMessage = '';

                            }

                        }

                        if ($this->_partialMessageStream) {

                            $payloadLength = strlen($framePayload);
                            $writtenBytes = 0;
                            $res = NULL;

                            do {

                                $res = @fwrite($this->_partialMessageStream, $framePayload);
                                if ($res !== FALSE) {
                                    $writtenBytes += $res;
                                }

                            } while ($res !== FALSE && $writtenBytes < $payloadLength);

                            if ($res === FALSE) {
                                yield new Update\Error(Update\Error::C_READ_INVALID_TARGET_STREAM, $this);
                            }

                        } else {
                            $this->_partialMessage .= $framePayload;
                        }

                        if ($headers[Framer::IND_FIN]) {

                            yield new Update\Read(Update\Read::C_READ, $this, $this->_partialMessageOpcode, $this->_partialMessage, $this->_partialMessageStream);

                            $this->_partialMessageOpcode = NULL;
                            $this->_partialMessageStream = NULL;
                            $this->_partialMessage = NULL;

                            $this->_utfValidationState = \PHPWebSocket::UTF8_ACCEPT;

                        }

                        break;
                    case \PHPWebSocket::OPCODE_CLOSE_CONNECTION:

                        \PHPWebSocket::Log(LOG_DEBUG, 'Got disconnect');

                        $disconnectMessage = '';
                        $code = \PHPWebSocket::CLOSECODE_NORMAL;

                        if (strlen($framePayload) > 1) {

                            $code = unpack('n', substr($framePayload, 0, 2))[1]; // Send back the same disconnect code if provided
                            if (!\PHPWebSocket::IsValidCloseCode($code)) {

                                $disconnectMessage = 'Invalid close code provided: ' . $code;
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOL_ERROR;

                            } elseif (!preg_match('//u', substr($framePayload, 2))) {

                                $disconnectMessage = 'Received Non-UTF8 close frame payload';
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOL_ERROR;

                            } else {
                                $disconnectMessage = substr($framePayload, 2);
                            }

                        }

                        $this->_remoteSentDisconnect = TRUE;

                        yield new Update\Read(Update\Read::C_READ_DISCONNECT, $this, $opcode, $framePayload);

                        if ($this->_weInitiateDisconnect) {

                            \PHPWebSocket::Log(LOG_DEBUG, '  We initiated the disconnect, close the connection');

                            $this->close();
                            yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);

                        } elseif (!$this->_weSentDisconnect) {

                            \PHPWebSocket::Log(LOG_DEBUG, '  Remote initiated the disconnect, echo disconnect');

                            $this->sendDisconnect($code, $disconnectMessage); // Echo the disconnect
                            $this->setCloseAfterWrite();

                        }

                        break;
                    case \PHPWebSocket::OPCODE_PING:

                        $pingPayload = (is_string($framePayload) ? $framePayload : '');

                        yield new Update\Read(Update\Read::C_PING, $this, $opcode, $pingPayload);
                        $pongs[] = $pingPayload;

                        break;
                    case \PHPWebSocket::OPCODE_PONG:

                        $pongPayload = (is_string($framePayload) ? $framePayload : '');
                        yield new Update\Read(Update\Read::C_PONG, $this, $opcode, $pongPayload);

                        break;
                    default:
                        throw new \UnexpectedValueException('Got unknown opcode from framer!');
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
     * Writes the current buffer to the connection
     *
     * @return \Generator
     */
    public function handleWrite() : \Generator {

        \PHPWebSocket::Log(LOG_DEBUG, __METHOD__);

        if ($this->_writeBuffer !== NULL) { // If our current frame hasn't finished yet
            \PHPWebSocket::Log(LOG_DEBUG, 'Resuming write');
        } elseif (!empty($this->_priorityFramesBuffer)) { // Certain frames take priority over normal frames

            \PHPWebSocket::Log(LOG_DEBUG, 'Starting new write (Priority)');
            $this->_writeBuffer = array_shift($this->_priorityFramesBuffer);

        } elseif (!empty($this->_framesBuffer)) {

            \PHPWebSocket::Log(LOG_DEBUG, 'Starting new write');
            $this->_writeBuffer = array_shift($this->_framesBuffer);

        }

        if ($this->_writeBuffer !== NULL) {

            $bytesToWrite = strlen($this->_writeBuffer);

            \PHPWebSocket::Log(LOG_DEBUG, '  Attempting to write ' . $bytesToWrite . ' bytes');

            $bytesWritten = @fwrite($this->getStream(), $this->_writeBuffer, min($this->getWriteRate(), $bytesToWrite));
            if ($bytesWritten === FALSE) {
                \PHPWebSocket::Log(LOG_DEBUG, '    fwrite failed');
                yield new Update\Error(Update\Error::C_WRITE, $this);
            } elseif ($bytesWritten === $bytesToWrite) {
                \PHPWebSocket::Log(LOG_DEBUG, '    All bytes written');
                $this->_writeBuffer = NULL;
            } else {
                \PHPWebSocket::Log(LOG_DEBUG, '    Written ' . $bytesWritten . ' bytes');
                $this->_writeBuffer = substr($this->_writeBuffer, $bytesWritten);
            }

        }

        if ($this->_closeAfterWrite && $this->isWriteBufferEmpty()) {
            \PHPWebSocket::Log(LOG_DEBUG, '      Close after write');
            $this->close();
        }

    }

    /**
     * Splits the provided data into frames of the specified size and sends them to the remote
     *
     * @param string $data
     * @param int    $opcode
     * @param int    $frameSize
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function writeMultiFramed(string $data, int $opcode = \PHPWebSocket::OPCODE_FRAME_TEXT, int $frameSize = 65535) {

        if ($opcode !== \PHPWebSocket::OPCODE_FRAME_TEXT && $opcode !== \PHPWebSocket::OPCODE_FRAME_BINARY) {
            throw new \InvalidArgumentException('Only OPCODE_FRAME_TEXT and OPCODE_FRAME_BINARY are supported in ' . __METHOD__);
        }
        if ($frameSize < 1) {
            throw new \LogicException('FrameSize should be at least 1');
        }

        $frames = str_split($data, $frameSize);
        end($frames);
        $lastKey = key($frames);

        foreach ($frames as $key => $frame) {
            $this->write($frame, $opcode, $key === $lastKey);
        }

    }

    /**
     * Returns the stream to write to for this specific message or NULL to use a buffer
     *
     * @param array $headers
     *
     * @return resource|null|bool
     */
    protected function _getStreamForNewMessage(array $headers) {

        $retValue = TRUE;
        if ($this->_newMessageStreamCallback) {

            $retValue = call_user_func($this->_newMessageStreamCallback, $headers);
            if (!is_bool($retValue) && !is_resource($retValue)) {
                throw new \UnexpectedValueException('Got an invalid return value, expected boolean or resource, got ' . gettype($retValue));
            }

        }

        return $retValue;
    }

    /**
     * Writes a raw string to the buffer, if priority is set to TRUE it will be send before normal priority messages
     *
     * @param string $data
     * @param bool   $priority
     */
    public function writeRaw(string $data, bool $priority) {

        if ($priority) {
            $this->_priorityFramesBuffer[] = $data;
        } else {
            $this->_framesBuffer[] = $data;
        }

    }

    /**
     * Queues a string to be written to the remote
     *
     * @param string $data
     * @param int    $opcode
     * @param bool   $isFinal
     */
    public function write(string $data, int $opcode = \PHPWebSocket::OPCODE_FRAME_TEXT, bool $isFinal = TRUE) {
        $this->writeRaw(Framer::Frame($data, $this->_shouldMask(), $opcode, $isFinal), \PHPWebSocket::IsPriorityOpcode($opcode));
    }

    /**
     * Sends a disconnect message to the remote, this causes the connection to be closed once they responds with its disconnect message
     *
     * @param int    $code
     * @param string $reason
     */
    public function sendDisconnect(int $code, string $reason = '') {

        if (!$this->_remoteSentDisconnect) {
            $this->_weInitiateDisconnect = TRUE;
        }

        $this->_weSentDisconnect = TRUE;

        $this->write(pack('n', $code) . $reason, \PHPWebSocket::OPCODE_CLOSE_CONNECTION);

    }

    /**
     * Returns TRUE if we are disconnecting
     *
     * @return bool
     */
    public function isDisconnecting() : bool {
        return $this->_weSentDisconnect || $this->_remoteSentDisconnect;
    }

    /**
     * Checks if the remote is in error by sending us one of the RSV bits
     *
     * @param array $headers
     *
     * @return bool
     */
    protected function _checkRSVBits(array $headers) : bool {

        if (($headers[Framer::IND_RSV1] && !$this->isRSVBitAllowed(1)) || ($headers[Framer::IND_RSV2] && !$this->isRSVBitAllowed(2)) || ($headers[Framer::IND_RSV3] && !$this->isRSVBitAllowed(3))) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Sets the callable that gets called after the first message part has been read to get the optional stream to write to
     * If no stream but FALSE is returned in this callback the connection will be closed with code CLOSECODE_UNSUPPORTED_PAYLOAD
     * If TRUE is returned from the callback a memory buffer will be used instead
     *
     * @param callable|null $callable
     */
    public function setNewMessageStreamCallback(callable $callable = NULL) {
        $this->_newMessageStreamCallback = $callable;
    }

    /**
     * Sets if the provided reserved bit is allowed to be set
     *
     * @param int  $bit
     * @param bool $allowed
     */
    public function setRSVBitAllowed(int $bit, bool $allowed) {

        if (!isset($this->_allowedRSVBits[$bit])) {
            throw new \LogicException('Bit ' . $bit . ' is not a valid RSV bit!');
        }

        $this->_allowedRSVBits[$bit] = $allowed;

    }

    /**
     * Returns if the provided reserved bit is allowed to be set
     *
     * @param int $bit
     *
     * @return bool
     */
    public function isRSVBitAllowed(int $bit) : bool {

        if (!isset($this->_allowedRSVBits[$bit])) {
            throw new \LogicException('Bit ' . $bit . ' is not a valid RSV bit!');
        }

        return $this->_allowedRSVBits[$bit];
    }

    /**
     * Returns the timestamp at which the connection was opened
     *
     * @return float|null
     */
    public function getOpenedTimestamp() {
        return $this->_openedTimestamp;
    }

    /**
     * Sets the maximum amount of bytes to write per cycle
     *
     * @param int $rate
     */
    public function setWriteRate(int $rate) {
        $this->_writeRate = $rate;
    }

    /**
     * Returns the maximum amount of bytes to write per cycle
     *
     * @return int
     */
    public function getWriteRate() : int {
        return $this->_writeRate;
    }

    /**
     * Sets the maximum amount of bytes to read per cycle
     *
     * @param int $rate
     */
    public function setReadRate(int $rate) {
        $this->_readRate = $rate;
    }

    /**
     * Returns the maximum amount of bytes to read per cycle
     *
     * @return int
     */
    public function getReadRate() : int {
        return $this->_readRate;
    }

    /**
     * Returns if the frame we are writing should be masked
     *
     * @return bool
     */
    abstract protected function _shouldMask() : bool;

    /**
     * Returns TRUE if our connection is open
     *
     * @return bool
     */
    abstract public function isOpen() : bool;

    /**
     * Simply closes the connection
     */
    abstract public function close();

    public function __destruct() {

        if ($this->isOpen()) {
            $this->close();
        }

    }
}
