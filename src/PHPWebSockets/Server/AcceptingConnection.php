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

use PHPWebSockets\TStreamContainerDefaults;
use PHPWebSockets\IStreamContainer;
use PHPWebSockets\TLogAware;
use PHPWebSockets\Server;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LogLevel;

class AcceptingConnection implements IStreamContainer, LoggerAwareInterface {

    use TStreamContainerDefaults;
    use TLogAware;

    /**
     * The websocket server related to this connection
     *
     * @var \PHPWebSockets\Server
     */
    protected $_server = NULL;

    /**
     * The resource stream
     *
     * @var resource
     */
    protected $_stream = NULL;

    public function __construct(Server $server, $stream) {

        $this->_server = $server;
        $this->_stream = $stream;

        // Inherit the logger from the server
        $serverLogger = $server->getLogger();
        if ($serverLogger !== \PHPWebSockets::GetLogger()) {
            $this->setLogger($serverLogger);
        }

        stream_set_timeout($this->_stream, 1);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

    }

    /**
     * Handles exceptional data reads
     *
     * @return \Generator
     */
    public function handleExceptional() : \Generator {
        throw new \LogicException('OOB data is not handled for an accepting stream!');
    }

    /**
     * Writes the current buffer to the connection
     *
     * @return \Generator
     */
    public function handleWrite() : \Generator {
        throw new \LogicException('An accepting socket should never write!');
    }

    /**
     * Attempts to read from our connection
     *
     * @return \Generator
     */
    public function handleRead() : \Generator {
        yield from $this->_server->gotNewConnection();
    }

    /**
     * Returns the related websocket server
     *
     * @return \PHPWebSockets\Server
     */
    public function getServer() : Server {
        return $this->_server;
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
     * Returns if our connection is open
     *
     * @return bool
     */
    public function isOpen() : bool {
        return is_resource($this->_stream);
    }

    /**
     * Closes the stream
     *
     * @param bool $cleanup If we should remove our unix socket if we used one
     */
    public function close(bool $cleanup = TRUE) {

        if (is_resource($this->_stream)) {
            fclose($this->_stream);
            $this->_stream = NULL;
        }

        $address = $this->_server->getAddress();
        $pos = strpos($address, '://');
        if ($pos !== FALSE) {

            $protocol = substr($address, 0, $pos);
            switch ($protocol) {
                case 'unix':
                case 'udg':

                    if (!$cleanup) {
                        $this->_log(LogLevel::DEBUG, 'Not cleaning up');
                        break;
                    }

                    $path = substr($address, $pos + 3);
                    if (file_exists($path)) {

                        if (!$cleanup) {
                            $this->_log(LogLevel::DEBUG, 'Not cleaning up ' . $path);
                        } else {

                            $this->_log(LogLevel::DEBUG, 'Unlinking: ' . $path);
                            unlink($path);

                        }

                    }

                    break;
            }

        }

    }

    public function __destruct() {

        if ($this->isOpen()) {
            $this->close();
        }

    }

    public function __toString() {
        return 'AWSConnection #' . (int) $this->getStream() . ' @ ' . $this->_server;
    }
}
