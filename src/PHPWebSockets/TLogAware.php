<?php

declare(strict_types = 1);

/*
 * - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
 * The MIT License (MIT)
 *
 * Copyright (c) 2021 Kevin Meijer
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

use Psr\Log\LoggerInterface;

trait TLogAware {

    /**
     * The logger
     *
     * @var \Psr\Log\LoggerInterface|null
     */
    protected $_logger = NULL;

    /**
     * Sets the logger
     *
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger) : void {
        $this->_logger = $logger;
    }

    /**
     * Returns the logger to use
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger() : LoggerInterface {

        if ($this->_logger === NULL) {
            return \PHPWebSockets::GetLogger();
        }

        return $this->_logger;
    }

    /**
     * Logs a message to set logger
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    protected function _log(string $level, string $message, array $context = []) : void {

        $this->getLogger()->log($level, $message, array_merge([
            'subject' => $this,
        ], $context));

    }
}
