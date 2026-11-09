<?php

namespace Git\Engine\Model;

final class DiffHunk
{
    /** @var list<DiffLine> */
    public array $lines = [];

    public function __construct(
        public readonly string $header,
    ) {
    }
}
