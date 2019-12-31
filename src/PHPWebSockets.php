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

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class PHPWebSockets {

    const   WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const   OPCODE_CONTINUE = 0,
            OPCODE_FRAME_TEXT = 1,
            OPCODE_FRAME_BINARY = 2,
            OPCODE_CLOSE_CONNECTION = 8,
            OPCODE_PING = 9,
            OPCODE_PONG = 10;

    const   CLOSECODE_NORMAL = 1000,
            CLOSECODE_ENDPOINT_CLOSING = 1001,
            CLOSECODE_PROTOCOL_ERROR = 1002,
            CLOSECODE_UNSUPPORTED_PAYLOAD = 1003,
            // CLOSECODE_                           = 1004, // Reserved
            CLOSECODE_NO_STATUS = 1005,
            CLOSECODE_ABNORMAL_DISCONNECT = 1006,
            CLOSECODE_INVALID_PAYLOAD = 1007,
            CLOSECODE_POLICY_VIOLATION = 1008,
            CLOSECODE_PAYLOAD_TO_LARGE = 1009,
            CLOSECODE_EXTENSION_NEGOTIATION_FAILURE = 1010,
            CLOSECODE_UNEXPECTED_CONDITION = 1011,
            CLOSECODE_SERVICE_RESTARTING = 1012,
            CLOSECODE_TRY_AGAIN_LATER = 1013,
            CLOSECODE_BAD_GATEWAY = 1014,
            CLOSECODE_TLS_HANDSHAKE_FAILURE = 1015;

    const UTF8_ACCEPT = 0,
          UTF8_REJECT = 1;

    const HTTP_STATUSCODES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * If we should add debug traces in the constructor of AUpdate
     *
     * @var bool
     */
    private static $_TraceAllUpdates = FALSE;

    /**
     * The current version of PHPWebSockets
     *
     * @var string|null
     */
    private static $_Version = NULL;

    /**
     * The log handler function
     *
     * @var \Psr\Log\LoggerInterface
     */
    private static $_Logger = NULL;

    /**
     * Checks for updates for the provided IStreamContainer objects
     *
     * @param \PHPWebSockets\IStreamContainer[] $updateObjects
     * @param float|null                        $timeout       The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @return \Generator|\PHPWebSockets\AUpdate[]
     */
    public static function MultiUpdate(array $updateObjects, float $timeout = NULL) : \Generator {

        $timeInt = NULL;
        $timePart = 0;

        if ($timeout !== NULL) {

            $timeInt = (int) floor($timeout);
            $timePart = (int) (fmod($timeout, 1) * 1000000);

        }

        /** @var \PHPWebSockets\IStreamContainer[] $objectStreamMap */
        $objectStreamMap = [];
        /** @var resource[] $exceptional */
        $exceptional = [];
        /** @var resource[] $write */
        $write = [];
        /** @var resource[] $read */
        $read = [];

        foreach ($updateObjects as $object) {

            if (!$object instanceof \PHPWebSockets\IStreamContainer) {
                throw new \InvalidArgumentException('Got invalid object, all provided objects should implement ' . \PHPWebSockets\IStreamContainer::class);
            }

            yield from $object->beforeStreamSelect();

            $stream = $object->getStream();

            $objectStreamMap[(int) $stream] = $object;
            $exceptional[] = $stream;
            if (!$object->isWriteBufferEmpty()) {
                $write[] = $stream;
            }
            $read[] = $stream;

            yield from $object->afterStreamSelect();

        }

        if (!empty($read) || !empty($write) || !empty($exceptional)) {

            $streams = @stream_select($read, $write, $exceptional, $timeInt, $timePart); // Stream select filters everything out of the arrays
            if ($streams === FALSE) {
                yield new \PHPWebSockets\Update\Error(\PHPWebSockets\Update\Error::C_SELECT);
            } else {

                if (!empty($read) || !empty($write) || !empty($exceptional)) {
                    static::Log(LogLevel::DEBUG, 'Read: ' . count($read) . ' Write: ' . count($write) . ' Except: ' . count($exceptional));
                }

                foreach ($read as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        static::Log(LogLevel::ERROR, 'Unable to find stream container related to stream during read!');
                        continue;
                    }

                    yield from $object->handleRead();

                }

                foreach ($write as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        static::Log(LogLevel::ERROR, 'Unable to find stream container related to stream during write!');
                        continue;
                    }

                    if (!is_resource($object->getStream())) { // Check if it is still open, it could have closed
                        static::Log(LogLevel::WARNING, 'Unable to complete write for stream container ' . $object . ', connection was closed');
                        continue;
                    }

                    yield from $object->handleWrite();

                }

                foreach ($exceptional as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        static::Log(LogLevel::ERROR, 'Unable to find stream container related to stream during exceptional read!');
                        continue;
                    }

                    if (!is_resource($object->getStream())) { // Check if it is still open, it could have closed
                        static::Log(LogLevel::WARNING, 'Unable to complete exceptional read for stream container, connection was closed');
                        continue;
                    }

                    static::Log(LogLevel::ERROR, 'Got exceptional for ' . $object);

                    yield from $object->handleExceptional();

                }

            }

        }

    }

    /**
     * Returns TRUE if the specified code is valid
     *
     * @param int  $code
     * @param bool $received If the close code is received as reason
     *
     * @return bool
     */
    public static function IsValidCloseCode(int $code, bool $received = TRUE) : bool {

        switch ($code) {
            case self::CLOSECODE_NORMAL:
            case self::CLOSECODE_ENDPOINT_CLOSING:
            case self::CLOSECODE_PROTOCOL_ERROR:
            case self::CLOSECODE_UNSUPPORTED_PAYLOAD:
            case self::CLOSECODE_INVALID_PAYLOAD:
            case self::CLOSECODE_POLICY_VIOLATION:
            case self::CLOSECODE_PAYLOAD_TO_LARGE:
            case self::CLOSECODE_EXTENSION_NEGOTIATION_FAILURE:
            case self::CLOSECODE_UNEXPECTED_CONDITION:
                return TRUE;
            case self::CLOSECODE_NO_STATUS:
            case self::CLOSECODE_ABNORMAL_DISCONNECT:
            case self::CLOSECODE_TLS_HANDSHAKE_FAILURE:
                return !$received;
            default:
                return $code >= 3000 && $code <= 4999;
        }

    }

    /**
     * Returns if the message with the provided opcode should be send with priority
     *
     * @param int $opcode
     *
     * @return bool
     */
    public static function IsPriorityOpcode(int $opcode) : bool {

        /*
         * Note:
         * We do not consider the opcode for CLOSE to be a priority since this would cause us to send the close frame before finishing our queue
         * In those cases it can be possible for the remote site to read both a close and another frame at the same time and process them in that order
         * That in return causes it to effectively receive a message from a closed connection
         */

        return $opcode !== self::OPCODE_CLOSE_CONNECTION && self::IsControlOpcode($opcode);
    }

    /**
     * Returns if the provided opcode is a control opcode
     *
     * @param int $opcode
     *
     * @return bool
     */
    public static function IsControlOpcode(int $opcode) : bool {
        return $opcode >= 8 && $opcode <= 15;
    }

    /**
     * Generates a random string
     *
     * @param int $length
     *
     * @return string
     */
    public static function RandomString(int $length) : string {

        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= chr(mt_rand(32, 93));
        }

        return $key;
    }

    /**
     * Validates a string for UTF8 validity, this allows for matching partial UTF8 string by passing the state each time on resume
     *
     * @copyright (c) 2008-2009 Bjoern Hoehrmann <bjoern@hoehrmann.de>
     *
     * @see http://bjoern.hoehrmann.de/utf-8/decoder/dfa/
     *
     * @param string $str
     * @param int    &$state
     *
     * @return bool
     */
    public static function ValidateUTF8(string $str, int &$state = self::UTF8_ACCEPT) : bool {

        $table = [
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // 00..1f
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // 20..3f
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // 40..5f
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // 60..7f
            1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, // 80..9f
            7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, // a0..bf
            8, 8, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, // c0..df
            0xa, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x3, 0x4, 0x3, 0x3, // e0..ef
            0xb, 0x6, 0x6, 0x6, 0x5, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, 0x8, // f0..ff
            0x0, 0x1, 0x2, 0x3, 0x5, 0x8, 0x7, 0x1, 0x1, 0x1, 0x4, 0x6, 0x1, 0x1, 0x1, 0x1, // s0..s0
            1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, 0, 1, 0, 1, 1, 1, 1, 1, 1, // s1..s2
            1, 2, 1, 1, 1, 1, 1, 2, 1, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 1, 1, 1, 1, 1, // s3..s4
            1, 2, 1, 1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 3, 1, 3, 1, 1, 1, 1, 1, 1, // s5..s6
            1, 3, 1, 1, 1, 1, 1, 3, 1, 3, 1, 1, 1, 1, 1, 1, 1, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // s7..s8
        ];

        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {

            $state = $table[256 + ($state << 4) + $table[ord($str[$i])]];
            if ($state === self::UTF8_REJECT) {
                return FALSE;
            }

        }

        return TRUE;
    }

    /**
     * Attempts to parse the provided string into key => value pairs based on the HTTP headers syntax
     *
     * @param string $rawHeaders
     *
     * @return array
     */
    public static function ParseHTTPHeaders(string $rawHeaders) : array {

        $headers = [];

        $lines = explode("\n", $rawHeaders);
        foreach ($lines as $line) {

            if (strpos($line, ':') !== FALSE) {

                $header = explode(':', $line, 2);
                $headers[strtolower(trim($header[0]))] = trim($header[1]);

            } elseif (stripos($line, 'get ') !== FALSE) {

                preg_match('/GET (.*) HTTP/i', $line, $reqResource);
                $headers['get'] = trim($reqResource[1]);

            } elseif (preg_match('#HTTP/\d+\.\d+ (\d+)#', $line)) {

                $pieces = explode(' ', $line, 3);
                $headers['status-code'] = intval($pieces[1]);
                $headers['status-text'] = $pieces[2];

            }

        }

        return $headers;
    }

    /**
     * Returns the text related to the provided error code, returns NULL if the code is unknown
     *
     * @param int $errorCode
     *
     * @return string|null
     */
    public static function GetStringForStatusCode(int $errorCode) {
        return self::HTTP_STATUSCODES[$errorCode] ?? NULL;
    }

    /**
     * Returns the version of PHPWebSockets
     *
     * @return string
     */
    public static function Version() : string {

        if (self::$_Version !== NULL) {
            return self::$_Version;
        }

        return self::$_Version = trim(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'VERSION'));
    }

    /**
     * @param \PHPWebSockets\AUpdate $update
     *
     * @return bool
     */
    public static function ShouldUpdateTrace(\PHPWebSockets\AUpdate $update) : bool {
        return self::$_TraceAllUpdates;
    }

    /**
     * @param bool $value
     *
     * @return void
     */
    public static function SetTraceAllUpdates(bool $value) {
        self::$_TraceAllUpdates = $value;
    }

    /**
     * Logs a message
     *
     * @param string $logLevel The log level
     * @param mixed  $message  The message to log
     * @param array  $context
     */
    public static function Log(string $logLevel, $message, array $context = []) {
        self::GetLogger()->log($logLevel, $message, $context);
    }

    /**
     * Sets the logger
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public static function SetLogger(LoggerInterface $logger) {
        self::$_Logger = $logger;
    }

    /**
     * Returns the logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    public static function GetLogger() : LoggerInterface {

        if (self::$_Logger === NULL) {
            self::$_Logger = new \PHPWebSockets\BasicLogger();
        }

        return self::$_Logger;
    }

    /**
     * The autoloader for non-composer including
     * Note: The method is not registered as autoloader, this still has to happen by calling spl_autoload_register([\PHPWebSockets::class, 'Autoload']);
     *
     * @param string $className
     */
    public static function Autoload(string $className) {

        if (substr($className, 0, 12) !== 'PHPWebSocket') {
            return;
        }

        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        if (file_exists($file)) {
            require_once($file);
        }

    }
}
