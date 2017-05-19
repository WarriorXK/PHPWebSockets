<?php
/**
 * Created by PhpStorm.
 * User: kevinmeijer
 * Date: 19/05/2017
 * Time: 15:00
 */

namespace PHPWebSocket;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class BasicLogger implements LoggerInterface {

    use LoggerTrait;

    /**
     * If we should log debug messages or not
     *
     * @var bool
     */
    protected $_debug = FALSE;

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = array()) {

        if ($level === LogLevel::DEBUG && !$this->_debug) {
            return;
        }

        echo date(DATE_ATOM) . ' PHPWebSocket: ' . strtoupper($level) . ') ' . ((string) $message) . PHP_EOL;

    }

    /**
     * Enables or disables debug logging
     *
     * @param bool $debug
     */
    public function setDebugMode(bool $debug) {
        $this->_debug = $debug;
    }

}
