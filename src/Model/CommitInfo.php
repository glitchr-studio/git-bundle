<?php

namespace Git\Model;

class CommitInfo
{
    public function __construct(
        public readonly string $sha,
        public readonly string $shortSha,
        public readonly string $message,
        public readonly string $subject,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly \DateTimeImmutable $authorDate,
        public readonly string $committerName,
        public readonly string $committerEmail,
        public readonly \DateTimeImmutable $committerDate,
        public readonly array $parentShas = [],
        public readonly ?string $diff = null,
        public readonly array $stats = [],
        /**
         * The references pointing at this commit, when it was read for a graph (Git2Service::getCommitGraph()).
         *
         * @var list<array{type: 'branch'|'tag'|'remote'|'head', name: string, head?: bool}>
         */
        public readonly array $refs = [],
    ) {}
}
