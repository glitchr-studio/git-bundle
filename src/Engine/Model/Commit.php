<?php

namespace Git\Engine\Model;

final class Commit
{
    /** @param list<string> $parents */
    public function __construct(
        public readonly string $id,
        public readonly array $parents,
        public readonly Person $author,
        public readonly Person $committer,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }

    public function shortId(): string
    {
        return substr($this->id, 0, 9);
    }

    public function isMerge(): bool
    {
        return \count($this->parents) > 1;
    }
}
