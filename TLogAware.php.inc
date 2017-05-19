<?php
/**
 * Created by PhpStorm.
 * User: kevinmeijer
 * Date: 19/05/2017
 * Time: 14:20
 */

namespace PHPWebSocket;

use Psr\Log\LoggerInterface;

trait TLogAware {

    /**
     * The logger
     *
     * @var \Psr\Log\LoggerInterface|NULL
     */
    protected $_logger = NULL;

    /**
     * Sets the logger
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger) {
        $this->_logger = $logger;
    }

    /**
     * Returns the set logger
     *
     * @return \Psr\Log\LoggerInterface|NULL
     */
    public function getLogger() {

        if ($this->_logger === NULL) {
            return \PHPWebSocket::GetLogger();
        }

        return $this->_logger;
    }

    /**
     * Logs a message to set logger
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     */
    protected function _log(string $level, string $message, array $context = []) {

        $this->getLogger()->log($level, $message, array_merge([
            'subject' => $this,
        ], $context));

    }

}
