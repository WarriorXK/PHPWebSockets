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
    use \PHPWebSocket\TStreamContainerDefaults;

    /**
     * The amount of bytes to read to complete our current frame
     *
     * @var int|null
     */
    protected $_currentFrameRemainingBytes = NULL;

    /**
     * The opcode of the current partial message
     *
     * @var int|null
     */
    protected $_partialMessageOpcode = NULL;

    /**
     * If we've initiated the disconnect
     *
     * @var bool
     */
    protected $_weInitiateDisconnect = FALSE;

    /**
     * If we've received the disconnect message from the remote
     *
     * @var bool
     */
    protected $_remoteSentDisconnect = FALSE;

    /**
     * The priority frames ready to be send (Takes priority over the normal frames buffer)
     *
     * @var string[]
     */
    protected $_priorityFramesBuffer = [];

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
     * If the RSV1 property is allowed on this connection
     *
     * @var bool
     */
    protected $_allowRSV1 = FALSE;

    /**
     * If the RSV2 property is allowed on this connection
     *
     * @var bool
     */
    protected $_allowRSV2 = FALSE;

    /**
     * If the RSV3 property is allowed on this connection
     *
     * @var bool
     */
    protected $_allowRSV3 = FALSE;

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
     * In here we attempt to find frames and unmask them, returns finished messages if available
     *
     * @param string $newData
     *
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
        $pongs = [];

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
            $framePayload = Framer::GetFramePayload($frame, $headers);
            if ($framePayload === NULL) {
                break; // Frame isn't ready yet
            } elseif ($framePayload === FALSE) {

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
                            $this->_partialMessage .= $framePayload;
                        } elseif ($this->_partialMessage !== NULL) {

                            $this->sendDisconnect(\PHPWebSocket::CLOSECODE_PROTOCOLERROR, 'Got new frame without completing the previous');
                            $this->setCloseAfterWrite();

                            yield new Update\Error(Update\Error::C_READ_INVALID_PAYLOAD, $this);

                            return;

                        } else {

                            $this->_partialMessageOpcode = $opcode;
                            $this->_partialMessage = $framePayload;

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

                        if (strlen($framePayload) > 1) {

                            $code = unpack('n', substr($framePayload, 0, 2))[1]; // Send back the same disconnect code if provided
                            if (!\PHPWebSocket::IsValidCloseCode($code)) {

                                $disconnectMessage = 'Invalid close code provided: ' . $code;
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOLERROR;

                            } elseif (!preg_match('//u', substr($framePayload, 2))) {

                                $disconnectMessage = 'Received Non-UTF8 close frame payload';
                                $code = \PHPWebSocket::CLOSECODE_PROTOCOLERROR;

                            } else {
                                $disconnectMessage = substr($framePayload, 2);
                            }

                        }

                        $this->_remoteSentDisconnect = TRUE;

                        yield new Update\Read(Update\Read::C_READ_DISCONNECT, $this, $opcode, $framePayload);

                        if ($this->_weInitiateDisconnect) {

                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '  We initiated the disconnect, close the connection');

                            $this->close();
                            yield new Update\Read(Update\Read::C_SOCK_DISCONNECT, $this);

                        } elseif (!$this->_weSentDisconnect) {

                            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, '  Remote initiated the disconnect, echo disconnect');

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
     * Writes the current buffer to the connection
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleWrite() : \Generator {

        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, __METHOD__);

        if ($this->_writeBuffer !== NULL) { // If our current frame hasn't finished yet
            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Resuming write');
        } elseif (!empty($this->_priorityFramesBuffer)) { // Certain frames take priority over normal frames

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Starting new write (Priority)');
            $this->_writeBuffer = array_shift($this->_priorityFramesBuffer);

        } elseif (!empty($this->_framesBuffer)) {

            \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Starting new write');
            $this->_writeBuffer = array_shift($this->_framesBuffer);

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
     * Splits the provided data into frames of the specified size and sends them to the remote
     *
     * @param string $data
     * @param int    $opcode
     * @param int    $frameSize
     *
     * @throws \Exception
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

        if (($headers[Framer::IND_RSV1] && !$this->_allowRSV1) || ($headers[Framer::IND_RSV2] && !$this->_allowRSV2) || ($headers[Framer::IND_RSV3] && !$this->_allowRSV3)) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Sets if we allow the RSV1 bit to be set
     *
     * @param bool $allow
     */
    public function setAllowRSV1(bool $allow) {
        $this->_allowRSV1 = $allow;
    }

    /**
     * Returns if we allow the RSV1 bit to be set
     *
     * @return bool
     */
    public function getAllowRSV1() : bool {
        return $this->_allowRSV1;
    }

    /**
     * Returns if we allow the RSV2 bit to be set
     *
     * @param bool $allow
     */
    public function setAllowRSV2(bool $allow) {
        $this->_allowRSV2 = $allow;
    }

    /**
     * Returns if we allow the RSV2 bit to be set
     *
     * @return bool
     */
    public function getAllowRSV2() : bool {
        return $this->_allowRSV2;
    }

    /**
     * Returns if we allow the RSV3 bit to be set
     *
     * @param bool $allow
     */
    public function setAllowRSV3(bool $allow) {
        $this->_allowRSV3 = $allow;
    }

    /**
     * Returns if we allow the RSV3 bit to be set
     *
     * @return bool
     */
    public function getAllowRSV3() : bool {
        return $this->_allowRSV3;
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
