<?php

namespace Git\Engine\Model;

final class BlameLine
{
    public function __construct(
        public readonly int $number,
        public readonly string $commit,
        public readonly string $author,
        public readonly \DateTimeImmutable $date,
        public readonly string $summary,
        public readonly string $text,
        public readonly bool $startsGroup,
    ) {
    }
}
