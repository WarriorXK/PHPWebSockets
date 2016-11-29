<?php

// - - - - - - - - - - - - - BEGIN LICENSE BLOCK - - - - - - - - - - - - -
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

declare(strict_types = 1);

namespace PHPWebSocket;

trait TStreamContainerDefaults {
    /**
     * Gets called just before stream_select gets called
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function beforeStreamSelect() : \Generator {
        if (FALSE) {
            yield;
        }
    }

    /**
     * Returns if we have (partial)frames ready to be send
     *
     * @return bool
     */
    public function isWriteBufferEmpty() : bool {
        return TRUE;
    }

    /**
     * Handles exceptional data reads
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleExceptional() : \Generator {
        if (FALSE) {
            yield;
        }
    }

    /**
     * Writes the current buffer to the connection
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleWrite() : \Generator {
        if (FALSE) {
            yield;
        }
    }

    /**
     * Attempts to read from our connection
     *
     * @return \PHPWebSocket\AUpdate[]
     */
    public function handleRead() : \Generator {
        if (FALSE) {
            yield;
        }
    }
}
