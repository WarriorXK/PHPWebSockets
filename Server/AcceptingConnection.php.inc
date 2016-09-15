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

class AcceptingConnection implements \PHPWebSocket\IStreamContainer {

    use \PHPWebSocket\TStreamContainerDefaults;

    /**
     * The websocket object related to this connection
     * @var \PHPWebSocket\Server
     */
    protected $_websocket = NULL;

    /**
     * The resource stream
     * @var resource
     */
    protected $_stream = NULL;

    public function __construct(\PHPWebSocket\Server $websocket, $stream) {

        $this->_websocket = $websocket;
        $this->_stream = $stream;

        stream_set_timeout($this->_stream, 1);
        stream_set_blocking($this->_stream, FALSE);
        stream_set_read_buffer($this->_stream, 0);
        stream_set_write_buffer($this->_stream, 0);

    }

    /**
     * Handles exceptional data reads
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleExceptional() : \Generator {
        throw new \LogicException('OOB data is not handled for an accepting stream!');
    }

    /**
     * Writes the current buffer to the connection
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleWrite() : \Generator {
        throw new \LogicException('An accepting socket should never write!');
    }

    /**
     * Attempts to read from our connection
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleRead() : \Generator {

        $this->_websocket->gotNewConnection($this, $this->_stream);

        if (FALSE) {
            yield NULL;
        }

    }

    /**
     * Returns the stream object for this connection
     * @return resource
     */
    public function getStream() {
        return $this->_stream;
    }

    /**
     * Closes the stream
     */
    public function close() {

        if (is_resource($this->_stream)) {
            fclose($this->_stream);
            $this->_stream = NULL;
        }

    }

}
