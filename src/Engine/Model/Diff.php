<?php

namespace Git\Engine\Model;

/** The files a diff touches, and whether it was cut at the reader's limit. */
final class Diff
{
    /** @param list<DiffFile> $files */
    public function __construct(
        public readonly array $files,
        public readonly bool $truncated = false,
    ) {
    }

    public function additions(): int
    {
        return array_sum(array_map(static fn (DiffFile $f): int => $f->additions, $this->files));
    }

    public function deletions(): int
    {
        return array_sum(array_map(static fn (DiffFile $f): int => $f->deletions, $this->files));
    }
}
