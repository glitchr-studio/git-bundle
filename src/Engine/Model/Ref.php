<?php

namespace Git\Engine\Model;

/** A branch or a tag, its tip, the commit it points at and when. */
final class Ref
{
    public function __construct(
        public readonly string $name,
        public readonly string $fullName,
        public readonly string $target,
        public readonly ?\DateTimeImmutable $date = null,
        public readonly string $subject = '',
    ) {
    }

    public function isTag(): bool
    {
        return str_starts_with($this->fullName, 'refs/tags/');
    }
}
