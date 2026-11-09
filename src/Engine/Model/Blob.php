<?php

namespace Git\Engine\Model;

/** A file's content at a revision, cut at the reader's limit. */
final class Blob
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly int $size,
        public readonly string $content,
        public readonly bool $truncated,
    ) {
    }

    /** Binary as git decides it: a NUL in the first 8000 bytes. */
    public function isBinary(): bool
    {
        return str_contains(substr($this->content, 0, 8000), "\0");
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, \PATHINFO_EXTENSION));
    }

    public function lineCount(): int
    {
        return '' === $this->content ? 0 : substr_count(rtrim($this->content, "\n"), "\n") + 1;
    }
}
