<?php

namespace Git\Engine\Model;

final class GrepMatch
{
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly string $text,
    ) {
    }
}
