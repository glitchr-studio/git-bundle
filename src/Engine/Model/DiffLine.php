<?php

namespace Git\Engine\Model;

/** A line of a hunk: context (" "), added ("+") or removed ("-"), and its numbers on either side. */
final class DiffLine
{
    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly ?int $oldNumber,
        public readonly ?int $newNumber,
    ) {
    }
}
