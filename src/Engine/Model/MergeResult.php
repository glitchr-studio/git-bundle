<?php

namespace Git\Engine\Model;

/** What a merge gave: the new commit, or the files in conflict. */
final class MergeResult
{
    /** @param list<string> $conflicts */
    public function __construct(
        public readonly ?string $commit,
        public readonly array $conflicts = [],
        public readonly bool $alreadyMerged = false,
    ) {
    }

    public function isMerged(): bool
    {
        return null !== $this->commit;
    }
}
