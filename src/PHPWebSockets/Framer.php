<?php

declare(strict_types = 1);

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2017 Kevin Meijer
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

use Psr\Log\LogLevel;

final class Framer {

    const   BYTE_FIN = 0b1000000000000000,
            BYTE_RSV1 = 0b0100000000000000,
            BYTE_RSV2 = 0b0010000000000000,
            BYTE_RSV3 = 0b0001000000000000,
            BYTE_OPCODE = 0b0000111100000000,
            BYTE_MASKED = 0b0000000010000000,
            BYTE_LENGTH = 0b0000000001111111;

    const   IND_FIN = 'fin',
            IND_RSV1 = 'rsv1',
            IND_RSV2 = 'rsv2',
            IND_RSV3 = 'rsv3',
            IND_OPCODE = 'opcode',
            IND_MASK = 'mask',
            IND_LENGTH = 'length',
            IND_MASKING_KEY = 'masking-key',
            IND_PAYLOAD_OFFSET = 'payload-offset';

    /**
     * Extracts the headers out of the provided frame, returns NULL if the provided frame has invalid headers
     *
     * @param string $frame
     *
     * @return array|null
     */
    public static function GetFrameHeaders(string $frame) {

        $frameLength = strlen($frame);
        if ($frameLength < 2) {
            return NULL;
        }

        $part1 = unpack('n1', $frame)[1];

        $headers = [
            self::IND_FIN            => (bool) ($part1 & self::BYTE_FIN),
            self::IND_RSV1           => (bool) ($part1 & self::BYTE_RSV1),
            self::IND_RSV2           => (bool) ($part1 & self::BYTE_RSV2),
            self::IND_RSV3           => (bool) ($part1 & self::BYTE_RSV3),
            self::IND_OPCODE         => ($part1 & self::BYTE_OPCODE) >> 8,
            self::IND_MASK           => (bool) ($part1 & self::BYTE_MASKED),
            self::IND_LENGTH         => ($part1 & self::BYTE_LENGTH),
            self::IND_MASKING_KEY    => NULL,
            self::IND_PAYLOAD_OFFSET => 2,
        ];

        if ($headers[self::IND_LENGTH] === 126) { // 16 bits

            if ($frameLength < 8) {
                return NULL;
            }

            $headers[self::IND_LENGTH] = unpack('n', substr($frame, 2, 2))[1];
            $headers[self::IND_PAYLOAD_OFFSET] += 2;

            if ($headers[self::IND_MASK]) {
                $headers[self::IND_MASKING_KEY] = substr($frame, 4, 4);
            }

        } elseif ($headers[self::IND_LENGTH] === 127) { // 64 bits

            if ($frameLength < 14) {
                return NULL;
            }

            $headers[self::IND_LENGTH] = unpack('J', substr($frame, 2, 8))[1];

            if ($headers[self::IND_MASK]) {
                $headers[self::IND_MASKING_KEY] = substr($frame, 10, 4);
            }

            $headers[self::IND_PAYLOAD_OFFSET] += 8;

        } elseif ($headers[self::IND_MASK]) { // 7 bits

            if ($frameLength < 6) {
                return NULL;
            }

            $headers[self::IND_MASKING_KEY] = substr($frame, 2, 4);

        }

        if ($headers[self::IND_MASK]) {
            $headers[self::IND_PAYLOAD_OFFSET] += 4;
        }

        return $headers;
    }

    /**
     * Returns the payload from the frame, returns NULL for incomplete frames and FALSE for protocol error
     *
     * @param string     $frame
     * @param array|null $headers
     *
     * @return string|bool|null
     */
    public static function GetFramePayload(string $frame, array $headers = NULL) {

        $headers = ($headers ?? self::GetFrameHeaders($frame));
        if ($headers === NULL) {
            return NULL;
        }

        $frameLength = strlen($frame);
        if ($frameLength < $headers[self::IND_PAYLOAD_OFFSET]) { // Frame headers incomplete
            return NULL;
        }

        $opcode = $headers[self::IND_OPCODE];
        switch ($opcode) {
            case \PHPWebSockets::OPCODE_CLOSE_CONNECTION:
            case \PHPWebSockets::OPCODE_PING:
            case \PHPWebSockets::OPCODE_PONG:

                if ($headers[self::IND_LENGTH] === 1 || $headers[self::IND_LENGTH] > 125 || !$headers[self::IND_FIN]) {
                    return FALSE;
                }

            // Fallthrough intended
            case \PHPWebSockets::OPCODE_CONTINUE:
            case \PHPWebSockets::OPCODE_FRAME_TEXT:
            case \PHPWebSockets::OPCODE_FRAME_BINARY:

                if ($frameLength < $headers[self::IND_PAYLOAD_OFFSET] + $headers[self::IND_LENGTH]) {
                    return NULL;
                }

                $payload = substr($frame, $headers[self::IND_PAYLOAD_OFFSET], $headers[self::IND_LENGTH]);
                if ($headers[self::IND_MASK]) {
                    $payload = self::ApplyMask($payload, $headers[self::IND_MASKING_KEY]);
                }

                return $payload;
            default:
                \PHPWebSockets::Log(LogLevel::WARNING, 'Encountered unknown opcode: ' . $opcode);

                return FALSE; // Failure, unknown action
        }

    }

    /**
     * Frames a message
     *
     * @param string $data
     * @param bool   $mask
     * @param int    $opcode
     * @param bool   $isFinal
     * @param bool   $rsv1
     * @param bool   $rsv2
     * @param bool   $rsv3
     *
     * @throws \RangeException
     * @throws \LogicException
     *
     * @return string
     */
    public static function Frame(string $data, bool $mask, int $opcode = \PHPWebSockets::OPCODE_FRAME_TEXT, bool $isFinal = TRUE, bool $rsv1 = FALSE, bool $rsv2 = FALSE, bool $rsv3 = FALSE) : string {

        if ($opcode < 0 || $opcode > 15) {
            throw new \RangeException('Invalid opcode, opcode should range between 0 and 15');
        }
        if (!$isFinal && \PHPWebSockets::IsControlOpcode($opcode)) {
            throw new \LogicException('Control frames must be final!');
        }

        $byte = $opcode << 8;

        if ($isFinal) {
            $byte |= self::BYTE_FIN;
        }
        if ($rsv1) {
            $byte |= self::BYTE_RSV1;
        }
        if ($rsv2) {
            $byte |= self::BYTE_RSV2;
        }
        if ($rsv3) {
            $byte |= self::BYTE_RSV3;
        }

        $dataLength = strlen($data);

        $maskingKey = '';
        if ($mask) {

            $maskingKey = random_bytes(4);
            $data = self::ApplyMask($data, $maskingKey);

            $byte |= self::BYTE_MASKED;

        }

        if ($dataLength < 126) { // 7 bits
            return pack('n', $byte | $dataLength) . $maskingKey . $data;
        } elseif ($dataLength < 65536) {  // 16 bits
            return pack('nn', $byte | 126, $dataLength) . $maskingKey . $data;
        } else {  // 64 bit
            return pack('nJ', $byte | 127, $dataLength) . $maskingKey . $data;
        }

    }

    /**
     * Applies the mask to the provided payload
     *
     * @param string $payload
     * @param string $maskingKey
     *
     * @return string
     */
    public static function ApplyMask(string $payload, string $maskingKey) : string {
        return (string) (str_pad('', strlen($payload), $maskingKey) ^ $payload);
    }
}
