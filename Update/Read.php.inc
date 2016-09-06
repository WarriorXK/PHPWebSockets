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

namespace PHPWebSocket\Update;

class Read extends \PHPWebSocket\AUpdate {

    const C_UNKNOWN = 0;
    const C_NEWCONNECTION = 1;
    const C_READ = 2;
    const C_PING = 3;
    const C_SOCK_DISCONNECT = 4;
    const C_CONNECTION_DENIED = 5;
    const C_CONNECTION_ACCEPTED = 6;
    const C_READ_DISCONNECT = 7;

    /**
     * The message from the client
     * @var string|NULL
     */
    protected $_message = NULL;

    /**
     * The opcode for this message
     * @var integer|NULL
     */
    protected $_opcode = NULL;

    public function __construct(int $code, $sourceObject = NULL, int $opcode = NULL, string $message = NULL) {

        parent::__construct($code, $sourceObject);

        $this->_message = $message;
        $this->_opcode = $opcode;

    }

    /**
     * Returns a description for the provided code
     * @param  integer $code
     * @return string
     */
    static public function StringForErrorCode(int $code) : string {

        switch ($code) {
            case self::C_UNKNOWN:
                return 'Unknown error';
            case self::C_NEWCONNECTION:
                return 'New connection';
            case self::C_READ:
                return 'Read';
            case self::C_PING:
                return 'Ping';
            case self::C_SOCK_DISCONNECT:
                return 'Socket disconnected';
            case self::C_CONNECTION_DENIED:
                return 'Connection denied';
            case self::C_CONNECTION_ACCEPTED:
                return 'Connection accepted';
            case self::C_READ_DISCONNECT:
                return 'Disconnect';
            default:
                return 'Unknown read code ' . $code;
        }

    }

    /**
     * Returns the message from the client
     * @return string|NULL
     */
    public function getMessage() {
        return $this->_message;
    }

    /**
     * Returns the opcode for this message
     * @return integer|NULL
     */
    public function getOpcode() {
        return $this->_opcode;
    }

    public function __toString() {
        return 'Read) ' . self::StringForErrorCode($this->getCode());
    }

}
