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

use PHPWebSockets\AUpdate;

class Error extends AUpdate {

    public const C_UNKNOWN = 0,
                 C_SELECT = 1,
                 C_READ = 2,
                 C_READ_EMPTY = 3,
                 C_READ_UNHANDLED = 4,
                 C_READ_HANDSHAKE_FAILURE = 5,
                 C_READ_HANDSHAKE_TO_LARGE = 6,
                 C_READ_INVALID_PAYLOAD = 7,
                 C_READ_INVALID_HEADERS = 8,
                 C_READ_UNEXPECTED_DISCONNECT = 9,
                 C_READ_PROTOCOL_ERROR = 10,
                 C_READ_RSV_BIT_SET = 11,
                 C_WRITE = 12,
                 C_ACCEPT_TIMEOUT_PASSED = 13,
                 C_WRITE_INVALID_TARGET_STREAM = 14,
                 C_READ_DISCONNECT_DURING_HANDSHAKE = 15,
                 C_DISCONNECT_TIMEOUT = 16,
                 C_READ_NO_STREAM_FOR_NEW_MESSAGE = 17,
                 C_ASYNC_CONNECT_FAILED = 18;

    /**
     * Returns a description for the provided error code
     *
     * @param int $code
     *
     * @return string
     */
    public static function StringForCode(int $code) : string {

        $codes = [
            self::C_UNKNOWN                            => 'Unknown error',
            self::C_SELECT                             => 'Select error',
            self::C_READ                               => 'Read error',
            self::C_READ_EMPTY                         => 'Empty read',
            self::C_READ_UNHANDLED                     => 'Unhandled read',
            self::C_READ_HANDSHAKE_FAILURE             => 'Handshake failure',
            self::C_READ_HANDSHAKE_TO_LARGE            => 'Handshake to large',
            self::C_READ_INVALID_PAYLOAD               => 'Invalid payload',
            self::C_READ_INVALID_HEADERS               => 'Invalid headers',
            self::C_READ_UNEXPECTED_DISCONNECT         => 'Unexpected disconnect',
            self::C_READ_PROTOCOL_ERROR                => 'Protocol error',
            self::C_READ_RSV_BIT_SET                   => 'RSV bit set while not being expected',
            self::C_WRITE                              => 'Write failure',
            self::C_ACCEPT_TIMEOUT_PASSED              => 'Accept timeout passed',
            self::C_READ_DISCONNECT_DURING_HANDSHAKE   => 'Disconnect during handshake',
            self::C_DISCONNECT_TIMEOUT                 => 'The remote failed to respond in time to our disconnect',
            self::C_READ_NO_STREAM_FOR_NEW_MESSAGE     => 'No stream was returned by the newMessageStreamCallback',
            self::C_ASYNC_CONNECT_FAILED               => 'Async connect failed',
        ];

        return $codes[$code] ?? 'Unknown error code ' . $code;
    }

    public function __toString() {
        $code = $this->getCode();

        return 'Error) ' . self::StringForCode($code) . ' (C: ' . $code . ')';
    }
}
