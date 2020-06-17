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

namespace PHPWebSockets;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LogLevel;

abstract class AConnection implements IStreamContainer, LoggerAwareInterface {

    use TStreamContainerDefaults;
    use TLogAware;

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
     * The timestamp at which we will close the connection if the remote doesn't reply with an disconnect, this will only be set if we initiated the disconnect
     *
     * @var float|null
     */
    protected $_cleanDisconnectTimeout = NULL;

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
     *
     * @var int
     */
    protected $_utfValidationState = \PHPWebSockets::UTF8_ACCEPT;

    /**
     * The maximum size the handshake can become
     *
     * @var int
     */
    protected $_maxHandshakeLength = 8192;

    /**
     * If we should yield an has closed event on the next update
     *
     * @var bool
     */
    protected $_shouldReportClose = FALSE;

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
     * The partial message if the current message hasn't finished yet
     *
     * @var string|null
     */
    protected $_partialMessage = NULL;

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
     * An overwrite to indicate if this connection is closed
     *
     * @var bool
     */
    protected $_isClosed = FALSE;

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
     * The resource stream
     *
     * @var resource
     */
    protected $_stream = NULL;

    /**
     * Sets the maximum size for the handshake in bytes
     *
     * @param int $maxLength
     *
     * @return void
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
     * Attempts to write until the write buffer is empty
     * Note: This will discard any reads that happen during this period
     *
     * @param float $timeout
     *
     * @return bool
     */
    public function writeUntilEmpty(float $timeout = NULL) : bool {

        $start = microtime(TRUE);
        do {

            iterator_to_array(\PHPWebSockets::MultiUpdate([$this], 1.0));

            if (!$this->isOpen()) {
                throw new \RuntimeException('Connection closed during write empty');
            }

            if ($timeout !== NULL && microtime(TRUE) - $start > $timeout) {
                return FALSE;
            }

        } while (!$this->isWriteBufferEmpty());

        return $this->isWriteBufferEmpty();
    }

    /**
     * Sets that we should close the connection after all our writes have finished
     *
     * @return void
     */
    public function setCloseAfterWrite() {
        $this->_closeAfterWrite = TRUE;
    }

    /**
     * Should be called after the path and stream has been set to initialize
     *
     * @return void
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
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    protected function _handlePacket(string $newData) : \Generator {

        $this->_log(LogLevel::DEBUG, __METHOD__);

        if ($this->_readBuffer === NULL) {
            $this->_readBuffer = $newData;
        } else {
            $this->_readBuffer .= $newData;
        }

        $bufferLength = strlen($this->_readBuffer);

        $orgBuffer = $this->_readBuffer;
        $numBytes = $bufferLength;
        $framePos = 0;
        $pongs = [];

        $this->_log(LogLevel::DEBUG, 'Handling packet, current buffer size: ' . $bufferLength);

        while ($framePos < $numBytes) {

            $headers = Framer::GetFrameHeaders($this->_readBuffer);
            if ($headers === NULL) { // Incomplete headers, probably due to a partial read
                break;
            }

            if (!$this->isOpen()) {
                $this->_log(LogLevel::WARNING, 'Got frame after close, dropping');

                return;
            }

            if (!$this->_checkRSVBits($headers)) {

                $this->sendDisconnect(\PHPWebSockets::CLOSECODE_PROTOCOL_ERROR, 'Invalid RSV value');
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_RSVBIT_SET, $this);

                return;
            }

            $frameSize = $headers[Framer::IND_LENGTH] + $headers[Framer::IND_PAYLOAD_OFFSET];
            if ($numBytes < $frameSize) {
                $this->_currentFrameRemainingBytes = $frameSize - $numBytes;
                $this->_log(LogLevel::DEBUG, 'Setting next read size to ' . $this->_currentFrameRemainingBytes);
                break;
            }

            $this->_currentFrameRemainingBytes = NULL;

            $this->_log(LogLevel::DEBUG, 'Expecting frame of length ' . $frameSize);

            $frame = substr($orgBuffer, $framePos, $frameSize);
            $framePayload = Framer::GetFramePayload($frame, $headers);
            if ($framePayload === NULL) {
                break; // Frame isn't ready yet
            } elseif ($framePayload === FALSE) {

                $this->sendDisconnect(\PHPWebSockets::CLOSECODE_PROTOCOL_ERROR);
                $this->setCloseAfterWrite();

                yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                return;
            } else {

                $opcode = $headers[Framer::IND_OPCODE];
                switch ($opcode) {
                    case \PHPWebSockets::OPCODE_CONTINUE:

                        if ($this->_partialMessage === NULL && $this->_partialMessageStream === NULL) {

                            $this->sendDisconnect(\PHPWebSockets::CLOSECODE_PROTOCOL_ERROR, 'Got OPCODE_CONTINUE but no frame that could be continued');
                            $this->setCloseAfterWrite();

                            yield new Update\Error(Update\Error::C_READ_PROTOCOL_ERROR, $this);

                            return;
                        }

                    // Fall through intended
                    case \PHPWebSockets::OPCODE_FRAME_TEXT:
                    case \PHPWebSockets::OPCODE_FRAME_BINARY:

                        if ($this->_remoteSentDisconnect) {

                            $this->_log(LogLevel::WARNING, 'Found frame AFTER remote has send us a disconnect frame, dropping');

                            $this->_resetFrameData();

                            return;
                        }

                        if (($this->_partialMessageOpcode ?: $opcode) === \PHPWebSockets::OPCODE_FRAME_TEXT) {

                            if (!\PHPWebSockets::ValidateUTF8($framePayload, $this->_utfValidationState) || ($headers[Framer::IND_FIN] && $this->_utfValidationState !== \PHPWebSockets::UTF8_ACCEPT)) {

                                $this->sendDisconnect(\PHPWebSockets::CLOSECODE_INVALID_PAYLOAD, 'Could not decode text frame as UTF-8');
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                                return;
                            }

                        }

                        if ($opcode !== \PHPWebSockets::OPCODE_CONTINUE) {

                            if ($this->_partialMessage !== NULL || $this->_partialMessageStream !== NULL) {

                                $this->sendDisconnect(\PHPWebSockets::CLOSECODE_PROTOCOL_ERROR, 'Got new frame without completing the previous');
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                                return;
                            }

                            $newMessageStream = $this->_getStreamForNewMessage($headers);
                            if ($newMessageStream === FALSE) {

                                $this->sendDisconnect(\PHPWebSockets::CLOSECODE_UNSUPPORTED_PAYLOAD);
                                $this->setCloseAfterWrite();

                                yield new Update\Error(Update\Error::C_READ_NO_STREAM_FOR_NEW_MESSAGE, $this);

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

                                $this->close();
                                yield new Update\Error(Update\Error::C_WRITE_INVALID_TARGET_STREAM, $this);

                            }

                        } else {
                            $this->_partialMessage .= $framePayload;
                        }

                        if ($headers[Framer::IND_FIN]) {

                            $newMessageOpCode = $this->_partialMessageOpcode;
                            $newMessageData = $this->_partialMessage;
                            $newMessageStream = $this->_partialMessageStream;

                            /*
                             * We have to call _resetFrameData before yielding the update
                             * If we don't, and the handler of the update tries to send and wait for a new reply we'd error with a C_READ_INVALID_PAYLOAD since we assume the message we're yielding the event for hasn't been completed yet
                             */
                            $this->_resetFrameData();

                            yield new Update\Read(Update\Read::C_READ, $this, $newMessageOpCode, $newMessageData, $newMessageStream);

                        }

                        break;
                    case \PHPWebSockets::OPCODE_CLOSE_CONNECTION:

                        $this->_log(LogLevel::DEBUG, 'Got disconnect');

                        $disconnectMessage = '';
                        $code = \PHPWebSockets::CLOSECODE_NORMAL;

                        if (strlen($framePayload) > 1) {

                            $code = unpack('n', substr($framePayload, 0, 2))[1]; // Send back the same disconnect code if provided
                            if (!\PHPWebSockets::IsValidCloseCode($code)) {

                                $disconnectMessage = 'Invalid close code provided: ' . $code;
                                $code = \PHPWebSockets::CLOSECODE_PROTOCOL_ERROR;

                            } elseif (!preg_match('//u', substr($framePayload, 2))) {

                                $disconnectMessage = 'Received Non-UTF8 close frame payload';
                                $code = \PHPWebSockets::CLOSECODE_PROTOCOL_ERROR;

                            } else {
                                $disconnectMessage = substr($framePayload, 2);
                            }

                        }

                        $this->_remoteSentDisconnect = TRUE;

                        if ($this->_weInitiateDisconnect) {

                            $this->_log(LogLevel::DEBUG, '  We initiated the disconnect, close the connection');

                            $this->_isClosed = TRUE;

                            $this->close();

                            yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);

                        } elseif (!$this->_weSentDisconnect) {

                            $this->_log(LogLevel::DEBUG, '  Remote initiated the disconnect, echo disconnect');

                            yield new Update\Read(Update\Read::C_READ_DISCONNECT, $this, $opcode, $framePayload);

                            $this->sendDisconnect($code, $disconnectMessage); // Echo the disconnect
                            $this->setCloseAfterWrite();

                        }

                        break;
                    case \PHPWebSockets::OPCODE_PING:

                        $pingPayload = (is_string($framePayload) ? $framePayload : '');

                        yield new Update\Read(Update\Read::C_PING, $this, $opcode, $pingPayload);
                        $pongs[] = $pingPayload;

                        break;
                    case \PHPWebSockets::OPCODE_PONG:

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
                $this->write($pongPayload, \PHPWebSockets::OPCODE_PONG);
            }

        }

    }

    /**
     * @return void
     */
    private function _resetFrameData() {

        $this->_partialMessageOpcode = NULL;
        $this->_partialMessageStream = NULL;
        $this->_partialMessage = NULL;

        $this->_utfValidationState = \PHPWebSockets::UTF8_ACCEPT;

    }

    /**
     * Writes the current buffer to the connection
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public function handleWrite() : \Generator {

        $this->_log(LogLevel::DEBUG, __METHOD__);

        if ($this->_writeBuffer !== NULL) { // If our current frame hasn't finished yet
            $this->_log(LogLevel::DEBUG, 'Resuming write');
        } elseif (!empty($this->_priorityFramesBuffer)) { // Certain frames take priority over normal frames

            $this->_log(LogLevel::DEBUG, 'Starting new write (Priority)');
            $this->_writeBuffer = array_shift($this->_priorityFramesBuffer);

        } elseif (!empty($this->_framesBuffer)) {

            $this->_log(LogLevel::DEBUG, 'Starting new write');
            $this->_writeBuffer = array_shift($this->_framesBuffer);

        }

        if ($this->_writeBuffer !== NULL) {

            $bytesToWrite = strlen($this->_writeBuffer);

            $this->_log(LogLevel::DEBUG, '  Attempting to write ' . $bytesToWrite . ' bytes');

            $bytesWritten = @fwrite($this->getStream(), $this->_writeBuffer, min($this->getWriteRate(), $bytesToWrite));
            if ($bytesWritten === FALSE) {
                $this->_log(LogLevel::DEBUG, '    fwrite failed');
                yield new Update\Error(Update\Error::C_WRITE, $this);
            } elseif ($bytesWritten === $bytesToWrite) {
                $this->_log(LogLevel::DEBUG, '    All bytes written');
                $this->_writeBuffer = NULL;
            } else {
                $this->_log(LogLevel::DEBUG, '    Written ' . $bytesWritten . ' bytes');
                $this->_writeBuffer = substr($this->_writeBuffer, $bytesWritten);
            }

        }

        if ($this->_closeAfterWrite && $this->isWriteBufferEmpty()) {
            $this->_log(LogLevel::DEBUG, '      Close after write');
            $this->close();
        }

    }

    /**
     * {@inheritdoc}
     */
    public function beforeStreamSelect() : \Generator {

        if ($this->_cleanDisconnectTimeout !== NULL && microtime(TRUE) >= $this->_cleanDisconnectTimeout) {

            $this->close();

            yield new Update\Error(Update\Error::C_DISCONNECT_TIMEOUT, $this);

        }

        if ($this->_shouldReportClose) {

            $this->_log(LogLevel::DEBUG, 'Reporting close');

            yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);

            $this->_shouldReportClose = FALSE;

            $this->_afterReportClose();

        }

    }

    /**
     * @return void
     */
    protected function _afterReportClose() {
        // For child classes
    }

    /**
     * Splits the provided data into frames of the specified size and sends them to the remote
     *
     * @param string $data
     * @param int    $opcode
     * @param int    $frameSize
     *
     * @return void
     */
    public function writeMultiFramed(string $data, int $opcode = \PHPWebSockets::OPCODE_FRAME_TEXT, int $frameSize = 65535) {

        if ($opcode !== \PHPWebSockets::OPCODE_FRAME_TEXT && $opcode !== \PHPWebSockets::OPCODE_FRAME_BINARY) {
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
     *
     * @return void
     */
    public function write(string $data, int $opcode = \PHPWebSockets::OPCODE_FRAME_TEXT, bool $isFinal = TRUE) {
        $this->writeRaw(Framer::Frame($data, $this->_shouldMask(), $opcode, $isFinal), \PHPWebSockets::IsPriorityOpcode($opcode));
    }

    /**
     * @param float|null $timeout
     *
     * @return bool
     */
    public function waitUntilDisconnect(float $timeout = NULL) : bool {

        $start = microtime(TRUE);
        do {

            iterator_to_array(\PHPWebSockets::MultiUpdate([$this], 1.0));

            if ($timeout !== NULL && microtime(TRUE) - $start > $timeout) {
                return FALSE;
            }

        } while ($this->isOpen());

        return TRUE;
    }

    /**
     * Sends a disconnect message to the remote, this causes the connection to be closed once they responds with its disconnect message
     *
     * @param int    $code
     * @param string $reason
     * @param float  $timeout
     *
     * @return void
     */
    public function sendDisconnect(int $code, string $reason = '', float $timeout = 10.0) {

        if (!$this->_remoteSentDisconnect) {

            $this->_weInitiateDisconnect = TRUE;
            $this->_cleanDisconnectTimeout = microtime(TRUE) + $timeout;

        }

        $this->_weSentDisconnect = TRUE;

        $this->write(pack('n', $code) . $reason, \PHPWebSockets::OPCODE_CLOSE_CONNECTION);

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
        return $headers[Framer::IND_RSV] === 0;
    }

    /**
     * Sets the callable that gets called after the first message part has been read to get the optional stream to write to
     * If no stream but FALSE is returned in this callback the connection will be closed with code CLOSECODE_UNSUPPORTED_PAYLOAD
     * If TRUE is returned from the callback a memory buffer will be used instead
     *
     * @param callable|null $callable
     *
     * @return void
     */
    public function setNewMessageStreamCallback(callable $callable = NULL) {
        $this->_newMessageStreamCallback = $callable;
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
     * Returns if we've received the handshake
     *
     * @return bool
     */
    public function hasHandshake() : bool {
        return $this->_hasHandshake;
    }

    /**
     * @return int|null
     */
    public function getResourceIndex() {
        return $this->_resourceIndex;
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
     *
     * @return void
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
     * Returns if our connection is open
     *
     * @return bool
     */
    public function isOpen() : bool {
        return $this->_isClosed === FALSE && is_resource($this->_stream);
    }

    /**
     * Simply closes the connection
     *
     * @return void
     */
    public function close() {

        if (!$this->_isClosed) {
            $this->_shouldReportClose = TRUE;
        }

        $this->_isClosed = TRUE;

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
