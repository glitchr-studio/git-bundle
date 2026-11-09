<?php

namespace Git\Engine\Model;

/** One line of a tree: a file, a folder, a link or a submodule. */
final class TreeEntry
{
    public function __construct(
        public readonly string $mode,
        public readonly string $type,
        public readonly string $id,
        public readonly string $name,
        public readonly string $path,
        public readonly ?int $size = null,
    ) {
    }

    public function isTree(): bool
    {
        return 'tree' === $this->type;
    }

    public function isSubmodule(): bool
    {
        return 'commit' === $this->type;
    }

    public function isLink(): bool
    {
        return '120000' === $this->mode;
    }
}
