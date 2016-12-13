<?php

declare(strict_types = 1);

/*
 * // - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * // The MIT License (MIT)
 * //
 * // Copyright (c) 2016 Kevin Meijer
 * //
 * // Permission is hereby granted, free of charge, to any person obtaining a copy
 * // of this software and associated documentation files (the "Software"), to deal
 * // in the Software without restriction, including without limitation the rights
 * // to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * // copies of the Software, and to permit persons to whom the Software is
 * // furnished to do so, subject to the following conditions:
 * //
 * // The above copyright notice and this permission notice shall be included in all
 * // copies or substantial portions of the Software.
 * //
 * // THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * // IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * // FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * // AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * // LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * // OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * // SOFTWARE.
 * // - - - - - - - - - - - - - - END LICENSE BLOCK - - - - - - - - - - - - -
 */

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

require_once(__DIR__ . '/IStreamContainer.php.inc');
require_once(__DIR__ . '/TStreamContainerDefaults.php.inc');
require_once(__DIR__ . '/AConnection.php.inc');
require_once(__DIR__ . '/AUpdate.php.inc');

require_once(__DIR__ . '/Client.php.inc');
require_once(__DIR__ . '/Framer.php.inc');
require_once(__DIR__ . '/Server.php.inc');

final class PHPWebSocket
{

    const   WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const   LOGLEVEL_DEBUG = 0,
            LOGLEVEL_NORMAL = 1,
            LOGLEVEL_WARNING = 2,
            LOGLEVEL_ERROR = 3;

    const   OPCODE_CONTINUE = 0,
            OPCODE_FRAME_TEXT = 1,
            OPCODE_FRAME_BINARY = 2,
            OPCODE_CLOSE_CONNECTION = 8,
            OPCODE_PING = 9,
            OPCODE_PONG = 10;

    const   CLOSECODE_NORMAL = 1000,
            CLOSECODE_ENDPOINT_CLOSING = 1001,
            CLOSECODE_PROTOCOLERROR = 1002,
            CLOSECODE_UNSUPPORTED_PAYLOAD = 1003,
            // CLOSECODE_                           = 1004, // Reserved
            CLOSECODE_NO_STATUS = 1005,
            CLOSECODE_ABNORMAL_DISCONNECT = 1006,
            CLOSECODE_INVALID_PAYLOAD = 1007,
            CLOSECODE_POLICY_VIOLATION = 1008,
            CLOSECODE_PAYLOAD_TO_LARGE = 1009,
            CLOSECODE_EXTENSION_NEGOTIATION_FAILURE = 1010,
            CLOSECODE_UNEXPECTED_CONDITION = 1011,
            CLOSECODE_TLS_HANDSHAKE_FAILURE = 1015;

    const HTTP_ERRORCODES = [
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
     * The log handler function
     *
     * @var callable|null
     */
    private static $_LogHandler = NULL;

    /**
     * The current version of PHPWebSocket
     *
     * @var string|null
     */
    private static $_Version = NULL;

    /**
     * If we're currently debugging
     *
     * @var bool
     */
    private static $_Debug = FALSE;

    /**
     * Checks for updates for the provided IStreamContainer objects
     *
     * @param \PHPWebSocket\IStreamContainer $updateObjects
     * @param float|null                     $timeout       The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @throws \InvalidArgumentException
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public static function MultiUpdate(array $updateObjects, float $timeout = NULL) : \Generator {

        $timeInt = NULL;
        $timePart = 0;

        if ($timeout !== NULL) {

            $timeInt = (int) floor($timeout);
            $timePart = (int) (fmod($timeout, 1) * 1000000);

        }

        $objectStreamMap = [];
        $exceptional = [];
        $write = [];
        $read = [];

        foreach ($updateObjects as $object) {

            if (!$object instanceof \PHPWebSocket\IStreamContainer) {
                throw new \InvalidArgumentException('Got invalid object, all provided objects should implement of \PHPWebSocket\IStreamContainer');
            }

            yield from $object->beforeStreamSelect();

            $stream = $object->getStream();

            $objectStreamMap[(int) $object->getStream()] = $object;
            $exceptional[] = $stream;
            if (!$object->isWriteBufferEmpty()) {
                $write[] = $stream;
            }
            $read[] = $stream;

        }

        if (!empty($read) || !empty($write) || !empty($exceptional)) {

            $streams = @stream_select($read, $write, $exceptional, $timeInt, $timePart); // Stream select filters everything out of the arrays
            if ($streams === FALSE) {
                yield new \PHPWebSocket\Update\Error(\PHPWebSocket\Update\Error::C_SELECT);

                return;
            } else {

                if (!empty($read) || !empty($write) || !empty($exceptional)) {
                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_DEBUG, 'Read: ' . count($read) . ' Write: ' . count($write) . ' Except: ' . count($exceptional));
                }

                foreach ($read as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find stream container related to stream during read!');
                        continue;
                    }

                    yield from $object->handleRead();

                }

                foreach ($write as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find stream container related to stream during write!');
                        continue;
                    }

                    if (!is_resource($object->getStream())) { // Check if it is still open, it could have closed
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_WARNING, 'Unable to complete write for stream container ' . $object . ', connection was closed');
                        continue;
                    }

                    yield from $object->handleWrite();

                }

                foreach ($exceptional as $stream) {

                    $object = $objectStreamMap[(int) $stream];
                    if ($object === NULL) {
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Unable to find stream container related to stream during exceptional read!');
                        continue;
                    }

                    if (!is_resource($object->getStream())) { // Check if it is still open, it could have closed
                        \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_WARNING, 'Unable to complete exceptional read for stream container, connection was closed');
                        continue;
                    }

                    \PHPWebSocket::Log(\PHPWebSocket::LOGLEVEL_ERROR, 'Got exceptional for ' . $object);

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
     */
    public static function IsValidCloseCode(int $code, bool $received = TRUE) : bool {

        switch ($code) {
            case self::CLOSECODE_NORMAL:
            case self::CLOSECODE_ENDPOINT_CLOSING:
            case self::CLOSECODE_PROTOCOLERROR:
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
        return self::IsControlOpcode($opcode);
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
    public static function RandomString(int $length = 16) : string {

        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= chr(mt_rand(32, 93));
        }

        return $key;
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
    public static function GetStringForErrorCode(int $errorCode) {
        return self::HTTP_ERRORCODES[$errorCode] ?? NULL;
    }

    /**
     * Returns the version of PHPWebSocket
     *
     * @return string
     */
    public static function Version() : string {

        if (self::$_Version !== NULL) {
            return self::$_Version;
        }

        return self::$_Version = trim(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'VERSION'));
    }

    /**
     * Logs a message
     *
     * @param int    $logLevel  The loglevel, @see \PHPWebSocket::LOGLEVEL_*
     * @param string $message   The message to log
     * @param bool   $forceShow If the message should be shown regardless of the loglevel
     */
    public static function Log(int $logLevel, $message, bool $forceShow = FALSE) {

        if (self::$_LogHandler !== NULL) {
            call_user_func_array(self::$_LogHandler, func_get_args());

            return;
        }

        if ($logLevel > self::LOGLEVEL_DEBUG || self::$_Debug || $forceShow) {
            echo 'PHPWebSocket: ' . $logLevel . ') ' . ((string) $message) . PHP_EOL;
        }

    }

    /**
     * Installs the log handler, the callable should accept the same arguments as \PHPWebSocket::Log
     *
     * @param callable $logHandler
     */
    public static function SetLogHandler(callable $logHandler = NULL) {
        self::$_LogHandler = $logHandler;
    }
}
