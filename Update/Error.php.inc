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

namespace PHPWebSocket\Update;

class Error extends \PHPWebSocket\AUpdate {

    const   C_UNKNOWN = 0,
            C_SELECT = 1,
            C_READ = 2,
            C_READ_EMPTY = 3,
            C_READ_UNHANDLED = 4,
            C_READ_HANDSHAKEFAILURE = 5,
            C_READ_HANDSHAKETOLARGE = 6,
            C_READ_INVALID_PAYLOAD = 7,
            C_READ_INVALID_HEADERS = 8,
            C_READ_UNEXPECTED_DISCONNECT = 9,
            C_READ_PROTOCOL_ERROR = 10,
            C_READ_RVSBIT_SET = 11,
            C_WRITE = 12,
            C_ACCEPT_TIMEOUT_PASSED = 13;

    /**
     * Returns a description for the provided error code
     *
     * @param int $code
     *
     * @return string
     */
    public static function StringForErrorCode(int $code) : string {

        switch ($code) {
            case self::C_UNKNOWN:
                return 'Unknown error';
            case self::C_SELECT:
                return 'Select error';
            case self::C_READ:
                return 'Read error';
            case self::C_READ_EMPTY:
                return 'Empty read';
            case self::C_READ_UNHANDLED:
                return 'Unhandled read';
            case self::C_READ_HANDSHAKEFAILURE:
                return 'Handshake failure';
            case self::C_READ_HANDSHAKETOLARGE:
                return 'Handshake to large';
            case self::C_READ_INVALID_PAYLOAD:
                return 'Invalid payload';
            case self::C_READ_INVALID_HEADERS:
                return 'Invalid headers';
            case self::C_READ_UNEXPECTED_DISCONNECT:
                return 'Unexpected disconnect';
            case self::C_READ_PROTOCOL_ERROR:
                return 'Protocol error';
            case self::C_READ_RVSBIT_SET:
                return 'RVS bit set while not being expected';
            case self::C_WRITE:
                return 'Write failure';
            case self::C_ACCEPT_TIMEOUT_PASSED:
                return 'Accept timeout passed';
            default:
                return 'Unknown error code ' . $code;
        }

    }

    public function __toString() {
        $code = $this->getCode();

        return 'Error) ' . self::StringForErrorCode($code) . ' (C: ' . $code . ')';
    }
}
