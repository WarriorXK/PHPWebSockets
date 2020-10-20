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

namespace PHPWebSockets\Update;

use PHPWebSockets\AConnection;
use PHPWebSockets\AUpdate;

class Read extends AUpdate {

    public const C_UNKNOWN = 0,
                 C_NEW_CONNECTION = 1,
                 C_READ = 2,
                 C_PING = 3,
                 C_PONG = 4,
                 C_SOCK_DISCONNECT = 5,
                 C_CONNECTION_DENIED = 6,
                 C_CONNECTION_ACCEPTED = 7,
                 C_READ_DISCONNECT = 8,
                 C_NEW_SOCKET_CONNECTED = 9,
                 C_NEW_SOCKET_CONNECTION_AVAILABLE = 10;

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

    /**
     * The resource pointing to the downloaded message
     *
     * @var resource|null
     */
    protected $_stream = NULL;

    public function __construct(int $code, AConnection $sourceConnection = NULL, int $opcode = NULL, string $message = NULL, $stream = NULL) {

        if ($stream !== NULL && !is_resource($stream)) {
            throw new \InvalidArgumentException('The $stream argument has to be NULL or a resource!');
        }

        parent::__construct($code, $sourceConnection);

        $this->_message = $message;
        $this->_opcode = $opcode;
        $this->_stream = $stream;

    }

    /**
     * Returns a description for the provided code
     *
     * @param int $code
     *
     * @return string
     */
    public static function StringForCode(int $code) : string {

        $codes = [
            self::C_UNKNOWN                         => 'Unknown error',
            self::C_NEW_CONNECTION                  => 'New connection',
            self::C_READ                            => 'Read',
            self::C_PING                            => 'Ping',
            self::C_PONG                            => 'Pong',
            self::C_SOCK_DISCONNECT                 => 'Socket disconnected',
            self::C_CONNECTION_DENIED               => 'Connection denied',
            self::C_CONNECTION_ACCEPTED             => 'Connection accepted',
            self::C_READ_DISCONNECT                 => 'Disconnect',
            self::C_NEW_SOCKET_CONNECTED            => 'New connection accepted',
            self::C_NEW_SOCKET_CONNECTION_AVAILABLE => 'New connection available',
        ];

        return $codes[$code] ?? 'Unknown read code ' . $code;
    }

    /**
     * Returns the message from the client
     *
     * @return string|null
     */
    public function getMessage() : ?string {
        return $this->_message;
    }

    /**
     * Returns the opcode for this message
     *
     * @return int|null
     */
    public function getOpcode() : ?int {
        return $this->_opcode;
    }

    /**
     * Returns the resource pointing to the downloaded message
     *
     * @return resource|null
     */
    public function getStream() {
        return $this->_stream;
    }

    public function __toString() {

        $code = $this->getCode();

        return 'Read) ' . self::StringForCode($code) . ' (C: ' . $code . ')';
    }
}
