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

namespace PHPWebSockets;

use Brightfish\Error;
use PHPWebSockets\AConnection;
use PHPWebSockets\IStreamContainer;
use PHPWebSockets\Server\Connection;
use PHPWebSockets\Update;

class UpdatesWrapper {

    /**
     * @var callable|null
     */
    private $_newConnectionHandler = NULL;

    /**
     * @var callable|null
     */
    private $_lastContactHandler = NULL;

    /**
     * @var callable|null
     */
    private $_disconnectHandler = NULL;

    /**
     * @var \PHPWebSockets\IStreamContainer[]
     */
    private $_streamContainers = [];

    /**
     * @var callable|null
     */
    private $_messageHandler = NULL;

    /**
     * @var callable|null
     */
    private $_errorHandler = NULL;

    /**
     * @var bool
     */
    private $_shouldRun = FALSE;

    public function __construct(array $streamContainers, callable $newConnectionHandler = NULL, callable $messageHandler = NULL, callable $disconnectHandler = NULL) {

        foreach ($streamContainers as $key => $container) {

            if (!$container instanceof IStreamContainer) {
                throw new \InvalidArgumentException('Entry at key ' . $key . ' is not an instance of ' . IStreamContainer::class);
            }

        }

        $this->_streamContainers = $streamContainers;

        $this->_newConnectionHandler = $newConnectionHandler;
        $this->_disconnectHandler = $disconnectHandler;
        $this->_messageHandler = $messageHandler;

    }

    /**
     * @param \PHPWebSockets\IStreamContainer $container
     */
    public function addStreamContainer(IStreamContainer $container) {
        $this->_streamContainers[] = $container;
    }

    /**
     * @param \PHPWebSockets\IStreamContainer $container
     *
     * @return bool
     */
    public function removeStreamContainer(IStreamContainer $container) : bool {

        $key = array_search($container, $this->_streamContainers, TRUE);
        if ($key !== FALSE) {
            unset($this->_streamContainers[$key]);
        }

        return $key !== FALSE;
    }

    /**
     * @param callable|null $callable
     */
    public function setNewConnectionHandler(callable $callable = NULL) {
        $this->_newConnectionHandler = $callable;
    }

    /**
     * @param callable|null $callable
     */
    public function setMessageHandler(callable $callable = NULL) {
        $this->_messageHandler = $callable;
    }

    /**
     * @param callable|null $callable
     */
    public function setDisconnectHandler(callable $callable = NULL) {
        $this->_messageHandler = $callable;
    }

    /**
     * Creates a runloop
     *
     * @param float|null    $timeout
     * @param callable|null $runloop
     */
    public function run(float $timeout = NULL, callable $runloop = NULL) {

        $this->_shouldRun = TRUE;
        while ($this->_shouldRun) {

            $this->update($timeout);

            if ($runloop) {

                $ret = $runloop($this);
                if ($ret === FALSE) {
                    $this->_shouldRun = FALSE;
                }

            }

        }

    }

    public function stop() {
        $this->_shouldRun = FALSE;
    }

    /**
     * @param float|null $timeout The amount of seconds to wait for updates, setting this value to NULL causes this function to block indefinitely until there is an update
     *
     * @return void
     */
    public function update(float $timeout = NULL) {

        $updates = \PHPWebSockets::MultiUpdate($this->_streamContainers, $timeout);
        foreach ($updates as $update) {

            if ($update instanceof Update\Read) {

                $code = $update->getCode();
                switch ($code) {
                    case Update\Read::C_NEWCONNECTION:
                        $this->_onNewConnection($update);
                        break;
                    case Update\Read::C_READ:
                        $this->_onRead($update);
                        break;
                    case Update\Read::C_PING:
                        $this->_onPing($update);
                        break;
                    case Update\Read::C_PONG:
                        $this->_onPong($update);
                        break;
                    case Update\Read::C_SOCK_DISCONNECT:
                        $this->_onSocketDisconnect($update);
                        break;
                    case Update\Read::C_CONNECTION_DENIED:
                        $this->_onConnectionRefused($update);
                        break;
                    case Update\Read::C_CONNECTION_ACCEPTED:
                        $this->_onConnect($update);
                        break;
                    case Update\Read::C_READ_DISCONNECT:
                        $this->_onDisconnect($update);
                        break;
                    case Update\Read::C_NEW_TCP_CONNECTION:
                        $this->_onTCPConnect($update);
                        break;
                    case Update\Read::C_NEW_TCP_CONNECTION_AVAILABLE:
//                        $this->_onTCPConnectionAvailable($update);
//                        break;
                    default:
                        throw new \UnexpectedValueException('Unknown or unsupported update code for read: ' . $code);
                }

            } elseif ($update instanceof Error) {

                $code = $update->getCode();
                switch ($code) {
                    case Update\Error::C_SELECT:
                        $this->_onSelectInterupt($update);
                        break;
                    case Update\Error::C_READ:
                        $this->_onReadFail($update);
                        break;
                    case Update\Error::C_READ_EMPTY:
                        $this->_onReadEmpty($update);
                        break;
                    case Update\Error::C_READ_UNHANDLED:
                        $this->_onUnhandledRead($update);
                        break;
                    case Update\Error::C_READ_HANDSHAKEFAILURE:
                        $this->_onHandshakeFailure($update);
                        break;
                    case Update\Error::C_READ_HANDSHAKETOLARGE:
                        $this->_onHandshakeToLarge($update);
                        break;
                    case Update\Error::C_READ_INVALID_PAYLOAD:
                        $this->_onInvalidPayload($update);
                        break;
                    case Update\Error::C_READ_INVALID_HEADERS:
                        $this->_onInvalidHeaders($update);
                        break;
                    case Update\Error::C_READ_UNEXPECTED_DISCONNECT:
                        $this->_onUnexpectedDisconnect($update);
                        break;
                    case Update\Error::C_READ_PROTOCOL_ERROR:
                        $this->_onProtocolError($update);
                        break;
                    case Update\Error::C_READ_RSVBIT_SET:
                        $this->_onInvalidRSVBit($update);
                        break;
                    case Update\Error::C_WRITE:
                        $this->_writeError($update);
                        break;
                    case Update\Error::C_ACCEPT_TIMEOUT_PASSED:
                        $this->_acceptTimeoutPassed($update);
                        break;
                    case Update\Error::C_WRITE_INVALID_TARGET_STREAM:
                        $this->_writeStreamInvalid($update);
                        break;
                    default:
                        throw new \UnexpectedValueException('Unknown or unsupported update code for error: ' . $code);
                }

            } else {
                throw new \UnexpectedValueException('Got unhandled update class: ' . get_class($update));
            }

        }

    }

    /*
     * Triggers
     */

    private function _triggerNewConnectionHandler(Connection $connection, int $code) {

        $accept = FALSE;
        if ($this->_newConnectionHandler) {
            $accept = call_user_func($this->_newConnectionHandler, $connection);
        }

        if ($accept) {
            $connection->accept();
        } else {
            $connection->deny(400);
        }

    }

    private function _triggerNewMessageHandler(AConnection $connection, string $message, int $opcode) {
        if ($this->_messageHandler) {
            call_user_func($this->_messageHandler, $connection, $message, $opcode);
        }
    }

    private function _triggerLastContact(AConnection $connection) {
        if ($this->_lastContactHandler) {
            call_user_func($this->_lastContactHandler, $connection);
        }
    }

    private function _triggerDisconnectHandler(AConnection $connection, bool $wasClean, int $reason) {
        if ($this->_disconnectHandler) {
            call_user_func($this->_disconnectHandler, $connection, $wasClean, $reason);
        }
    }

    private function _triggerErrorHandler(AConnection $connection, int $code) {
        if ($this->_errorHandler) {
            call_user_func($this->_errorHandler, $connection, $code);
        }
    }

    /*
     * Read events
     */

    private function _onNewConnection(Update\Read $update) {

        /** @var \PHPWebSockets\Server\Connection $source */
        $source = $update->getSourceConnection();

        $this->_triggerLastContact($source);
        $this->_triggerNewConnectionHandler($source, $update->getCode());

    }

    private function _onRead(Update\Read $update) {

        $source = $update->getSourceConnection();

        $this->_triggerLastContact($source);
        $this->_triggerNewMessageHandler($source, $update->getMessage(), $update->getOpcode());

    }

    private function _onPing(Update\Read $update) {
        $this->_triggerLastContact($update->getSourceConnection());
    }

    private function _onPong(Update\Read $update) {
        $this->_triggerLastContact($update->getSourceConnection());
    }

    private function _onSocketDisconnect(Update\Read $update) {
        // Nothing
    }

    private function _onConnectionRefused(Update\Read $update) {
        $this->_triggerDisconnectHandler($update->getSourceConnection(), TRUE, $update->getCode());
    }

    private function _onConnect(Update\Read $update) {
        $this->_triggerLastContact($update->getSourceConnection());
    }

    private function _onDisconnect(Update\Read $update) {
        $this->_triggerDisconnectHandler($update->getSourceConnection(), TRUE, $update->getCode());
    }

    private function _onTCPConnect(Update\Read $update) {
        // Nothing
    }

    private function _onTCPConnectionAvailable(Update\Read $update) {
        // Ignored for now
    }

    /*
     * Error events
     */

    private function _onSelectInterupt(Update\Error $update) {
        // Nothing
    }

    private function _onReadFail(Update\Error $update) {
        $this->_triggerErrorHandler($update->getSourceConnection(), $update->getCode());
    }

    private function _onReadEmpty(Update\Error $update) {
        $this->_triggerErrorHandler($update->getSourceConnection(), $update->getCode());
    }

    private function _onUnhandledRead(Update\Error $update) {
        // Nothing
    }

    private function _onHandshakeFailure(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _onHandshakeToLarge(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _onInvalidPayload(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _onInvalidHeaders(Update\Error $update) {
        $this->_triggerErrorHandler($update->getSourceConnection(), $update->getCode());
    }

    private function _onUnexpectedDisconnect(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _onProtocolError(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _onInvalidRSVBit(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _writeError(Update\Error $update) {
        $this->_triggerErrorHandler($update->getSourceConnection(), $update->getCode());
    }

    private function _acceptTimeoutPassed(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }

    private function _writeStreamInvalid(Update\Error $update) {

        $source = $update->getSourceConnection();

        $this->_triggerErrorHandler($source, $update->getCode());
        $this->_triggerDisconnectHandler($source, FALSE, $update->getCode());

    }
}
