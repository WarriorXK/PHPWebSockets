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

namespace PHPWebSocket\Update;

class Read extends \PHPWebSocket\AUpdate {
    const   C_UNKNOWN = 0,
            C_NEWCONNECTION = 1,
            C_READ = 2,
            C_PING = 3,
            C_PONG = 4,
            C_SOCK_DISCONNECT = 5,
            C_CONNECTION_DENIED = 6,
            C_CONNECTION_ACCEPTED = 7,
            C_READ_DISCONNECT = 8,
            C_NEW_TCP_CONNECTION = 9,
            C_NEW_TCP_CONNECTION_AVAILABLE = 10;

    /**
     * The message from the client
     *
     * @var string|null
     */
    protected $_message = NULL;

    /**
     * The opcode for this message
     *
     * @var int|null
     */
    protected $_opcode = NULL;

    public function __construct(int $code, $sourceObject = NULL, int $opcode = NULL, string $message = NULL) {

        parent::__construct($code, $sourceObject);

        $this->_message = $message;
        $this->_opcode = $opcode;

    }

    /**
     * Returns a description for the provided code
     *
     * @param int $code
     *
     * @return string
     */
    public static function StringForErrorCode(int $code) : string {

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
            case self::C_NEW_TCP_CONNECTION:
                return 'New TCP connection accepted';
            case self::C_NEW_TCP_CONNECTION_AVAILABLE:
                return 'New TCP connection available';
            default:
                return 'Unknown read code ' . $code;
        }

    }

    /**
     * Returns the message from the client
     *
     * @return string|null
     */
    public function getMessage() {
        return $this->_message;
    }

    /**
     * Returns the opcode for this message
     *
     * @return int|null
     */
    public function getOpcode() {
        return $this->_opcode;
    }

    public function __toString() {
        $code = $this->getCode();

        return 'Read) ' . self::StringForErrorCode($code) . ' (C: ' . $code . ')';
    }
}
