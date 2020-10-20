<?php

declare(strict_types = 1);

namespace PHPWebSockets;

interface ITaggable {

    /**
     * @param string|null $tag
     */
    public function setTag(?string $tag) : void;

    /**
     * @return string|null
     */
    public function getTag() : ?string;

}
