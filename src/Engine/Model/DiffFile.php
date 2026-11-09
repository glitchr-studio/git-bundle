<?php

namespace Git\Engine\Model;

/** One file of a diff: its names before and after, its hunks of lines. */
final class DiffFile
{
    /** @var list<DiffHunk> */
    public array $hunks = [];
    public bool $binary = false;
    public int $additions = 0;
    public int $deletions = 0;

    public function __construct(
        public string $oldPath,
        public string $newPath,
        public string $status = 'modified',
    ) {
    }

    public function path(): string
    {
        return 'deleted' === $this->status ? $this->oldPath : $this->newPath;
    }
}
