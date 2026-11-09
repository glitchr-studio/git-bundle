<?php

namespace Git\Service;

use Git\Model\CommitInfo;
use Git\Model\TreeEntry;

/**
 * What the viewer reads of a named repository (Git\Repository\RepositoryRegistry),
 * whatever reads it: the git binary (CliService, the default) or libgit2
 * through the php-git2 extension (Git2Service). `git.backend` chooses.
 * An unknown repository, ref, path or tag is a NotFoundHttpException.
 */
interface RepositoryViewerInterface
{
    /** @return array<string, array> */
    public function listRepositories(): array;

    public function hasRepository(string $name): bool;

    public function getRepositoryConfig(string $name): array;

    /** A ref (branch, tag, abbreviated id, "HEAD") as the full id of the commit it names. */
    public function resolveRef(string $repoName, string $ref): string;

    public function defaultRef(string $repoName): string;

    /** @return CommitInfo[] */
    public function getCommitLog(string $repoName, string $ref, int $limit = 30, int $offset = 0): array;

    /**
     * The commits reachable from any branch, tag or remote branch, children
     * before their parents, each with its parents and the references on it.
     *
     * @return CommitInfo[]
     */
    public function getCommitGraph(string $repoName, int $limit = 200, int $offset = 0): array;

    /** @return array<string, list<array{type: 'branch'|'tag'|'remote'|'head', name: string, head?: bool}>> */
    public function getReferencesByCommit(string $repoName): array;

    /** One commit with its unified diff against its first parent and its stats. */
    public function getCommit(string $repoName, string $sha): CommitInfo;

    /** @return TreeEntry[] folders first */
    public function getTree(string $repoName, string $ref, string $path = ''): array;

    /** @return array{content: string, size: int, is_binary: bool, sha: string} */
    public function getBlob(string $repoName, string $ref, string $path): array;

    /** @return array<string, array{name: string, sha: string, is_head: bool}> */
    public function getBranches(string $repoName): array;

    /** @return array<string, array{name: string, sha: string}> */
    public function getTags(string $repoName): array;

    /** @return array{name: string, sha: string, message: ?string, date: \DateTimeImmutable} */
    public function getTag(string $repoName, string $name): array;

    /** @return \Generator<string, array{content: string, filemode: int}> */
    public function walkTree(string $repoName, string $ref, string $path = ''): \Generator;

    /** @return list<array{name: string, path: string}> */
    public function pathBreadcrumbs(string $path): array;
}
