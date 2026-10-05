<?php

namespace Git\Service;

use Git\Model\CommitInfo;
use Git\Model\TreeEntry;
use Git\Repository\RepositoryRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Git2Service
{
    private RepositoryRegistry $registry;

    /**
     * @param RepositoryRegistry|array<string, array{path: string, label: string|null, description: string|null, default_branch: string}> $repositories
     *        the registry (configured + provided repositories), or a plain
     *        list of configured ones
     */
    public function __construct(RepositoryRegistry|array $repositories)
    {
        $this->registry = \is_array($repositories) ? new RepositoryRegistry($repositories) : $repositories;
    }

    /** @return array<string, array> */
    public function listRepositories(): array
    {
        return $this->registry->all();
    }

    public function hasRepository(string $name): bool
    {
        return $this->registry->has($name);
    }

    public function getRepositoryConfig(string $name): array
    {
        return $this->registry->get($name) ?? throw new NotFoundHttpException("Repository '$name' not found.");
    }

    private function openRepo(string $name)
    {
        $config = $this->getRepositoryConfig($name);
        $repo = git_repository_open($config['path']);
        if (!$repo) {
            throw new \RuntimeException("Cannot open repository at {$config['path']}");
        }
        return $repo;
    }

    /**
     * Resolve a ref (branch name, tag name, abbreviated SHA, "HEAD") to a full 40-char SHA.
     */
    public function resolveRef(string $repoName, string $ref): string
    {
        $repo = $this->openRepo($repoName);

        // Try as arbitrary revspec — handles branch names, tag names, short SHAs, HEAD, etc.
        // php-git2 throws on an unknown revspec: that is a 404, not a 500.
        try {
            $obj = git_revparse_single($repo, $ref);
        } catch (\Throwable) {
            $obj = null;
        }
        if ($obj) {
            // Dereference annotated tags to the target commit
            if (git_object_type($obj) === GIT_OBJ_TAG) {
                try {
                    $peeled = git_object_peel($obj, GIT_OBJ_COMMIT);
                    return git_object_id($peeled);
                } catch (\Throwable) {
                    // fall through and return tag OID
                }
            }
            return git_object_id($obj);
        }

        throw new NotFoundHttpException("Ref '$ref' not found in repository '$repoName'.");
    }

    public function defaultRef(string $repoName): string
    {
        $config = $this->getRepositoryConfig($repoName);
        return $this->resolveRef($repoName, $config['default_branch']);
    }

    /**
     * @return CommitInfo[]
     */
    public function getCommitLog(string $repoName, string $ref, int $limit = 30, int $offset = 0): array
    {
        $repo = $this->openRepo($repoName);
        $sha  = $this->resolveRef($repoName, $ref);

        $walk = git_revwalk_new($repo);
        git_revwalk_sorting($walk, GIT_SORT_TIME);
        git_revwalk_push($walk, $sha);

        $commits = [];
        $skipped = 0;
        while (($oid = git_revwalk_next($walk)) !== null && $oid !== false) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }
            $commits[] = $this->commitInfoFromSha($repo, $oid);
            if (count($commits) >= $limit) {
                break;
            }
        }

        git_revwalk_free($walk);
        return $commits;
    }

    /**
     * The history of every branch at once, as a commit graph is drawn from it:
     * the commits reachable from any branch, tag or remote branch, children
     * before their parents (topological order; by date among the ones no
     * parentage orders), each with its parents (CommitInfo::$parentShas) and
     * the references pointing at it (CommitInfo::$refs) - the checked-out
     * branch marked `head`, a detached HEAD given as a reference of its own.
     *
     * @return CommitInfo[]
     */
    public function getCommitGraph(string $repoName, int $limit = 200, int $offset = 0): array
    {
        $repo = $this->openRepo($repoName);
        $refs = $this->getReferencesByCommit($repoName);
        if (!$refs) {
            return [];
        }

        $walk = git_revwalk_new($repo);
        git_revwalk_sorting($walk, GIT_SORT_TOPOLOGICAL | GIT_SORT_TIME);
        foreach (array_keys($refs) as $sha) {
            git_revwalk_push($walk, (string) $sha);
        }

        $commits = [];
        $skipped = 0;
        $limit   = max(1, $limit);
        while (($oid = git_revwalk_next($walk)) !== null && $oid !== false) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }
            $commits[] = $this->commitInfoFromSha($repo, $oid, $refs[$oid] ?? []);
            if (count($commits) >= $limit) {
                break;
            }
        }

        git_revwalk_free($walk);
        return $commits;
    }

    /**
     * Every branch, tag and remote branch by the commit it points at
     * (an annotated tag: the commit it tags). The checked-out branch carries
     * `head: true`; a detached HEAD is a reference of type `head`.
     *
     * @return array<string, list<array{type: 'branch'|'tag'|'remote'|'head', name: string, head?: bool}>>
     */
    public function getReferencesByCommit(string $repoName): array
    {
        $repo = $this->openRepo($repoName);

        // The checked-out branch by its name: two branches on one commit are not both HEAD.
        $headName = null;
        $headSha  = null;
        $head     = @git_repository_head($repo);
        if ($head) {
            $headName = git_reference_name($head);
            $resolved = @git_reference_resolve($head);
            $headSha  = $resolved ? git_reference_target($resolved) : null;
        }

        $refs = [];
        foreach (git_reference_list($repo) as $refName) {
            $type = match (true) {
                str_starts_with($refName, 'refs/heads/')   => 'branch',
                str_starts_with($refName, 'refs/remotes/') => 'remote',
                default                                    => null,
            };
            // origin/HEAD only says which remote branch is the default one.
            if ($type === null || str_ends_with($refName, '/HEAD')) {
                continue;
            }
            try {
                $sha = git_reference_target(git_reference_resolve(git_reference_lookup($repo, $refName)));
            } catch (\Throwable) {
                continue;
            }
            if (!$sha) {
                continue;
            }
            $ref = ['type' => $type, 'name' => substr($refName, strlen($type === 'branch' ? 'refs/heads/' : 'refs/remotes/'))];
            if ($type === 'branch') {
                $ref['head'] = $refName === $headName;
            }
            $refs[$sha][] = $ref;
        }
        foreach ($this->getTags($repoName) as $tag) {
            $refs[$tag['sha']][] = ['type' => 'tag', 'name' => $tag['name']];
        }
        if ($headSha && !str_starts_with((string) $headName, 'refs/heads/')) {
            $refs[$headSha][] = ['type' => 'head', 'name' => 'HEAD'];
        }

        return $refs;
    }

    /**
     * Return a single CommitInfo including unified diff and stats.
     */
    public function getCommit(string $repoName, string $sha): CommitInfo
    {
        // git_commit_lookup zero-pads abbreviated ids; resolve them first.
        $sha  = $this->resolveRef($repoName, $sha);
        $repo = $this->openRepo($repoName);
        $info = $this->commitInfoFromSha($repo, $sha);

        $commitObj = git_commit_lookup($repo, $sha);
        $tree      = git_commit_tree($commitObj);

        $diff  = null;
        $stats = [];

        if (count($info->parentShas) > 0) {
            $parentCommit = git_commit_lookup($repo, $info->parentShas[0]);
            $parentTree   = git_commit_tree($parentCommit);
            $diffObj = git_diff_tree_to_tree($repo, $parentTree, $tree, []);
        } else {
            $diffObj = git_diff_tree_to_tree($repo, null, $tree, []);
        }

        if ($diffObj) {
            $diff     = git_diff_to_buf($diffObj, GIT_DIFF_FORMAT_PATCH);
            $statsObj = git_diff_get_stats($diffObj);
            if ($statsObj) {
                $stats = [
                    'files_changed' => git_diff_stats_files_changed($statsObj),
                    'insertions'    => git_diff_stats_insertions($statsObj),
                    'deletions'     => git_diff_stats_deletions($statsObj),
                ];
            }
        }

        return new CommitInfo(
            sha:           $info->sha,
            shortSha:      $info->shortSha,
            message:       $info->message,
            subject:       $info->subject,
            authorName:    $info->authorName,
            authorEmail:   $info->authorEmail,
            authorDate:    $info->authorDate,
            committerName: $info->committerName,
            committerEmail:$info->committerEmail,
            committerDate: $info->committerDate,
            parentShas:    $info->parentShas,
            diff:          $diff,
            stats:         $stats,
        );
    }

    /**
     * @return TreeEntry[]
     */
    public function getTree(string $repoName, string $ref, string $path = ''): array
    {
        $repo = $this->openRepo($repoName);
        $sha  = $this->resolveRef($repoName, $ref);

        $commitObj = git_commit_lookup($repo, $sha);
        $tree      = git_commit_tree($commitObj);

        if ($path !== '') {
            $entry = @git_tree_entry_bypath($tree, ltrim($path, '/'));
            if (!$entry) {
                throw new NotFoundHttpException("Path '$path' not found at ref '$ref'.");
            }
            $entryOid = git_tree_entry_id($entry);
            $tree     = git_tree_lookup($repo, $entryOid);
        }

        $count   = git_tree_entrycount($tree);
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $e = git_tree_entry_byindex($tree, $i);
            $entries[] = TreeEntry::fromGit2Entry($e);
        }

        usort($entries, static function (TreeEntry $a, TreeEntry $b): int {
            if ($a->isTree() !== $b->isTree()) {
                return $a->isTree() ? -1 : 1;
            }
            return strcmp($a->name, $b->name);
        });

        return $entries;
    }

    /**
     * Return raw blob content + metadata.
     */
    public function getBlob(string $repoName, string $ref, string $path): array
    {
        $repo = $this->openRepo($repoName);
        $sha  = $this->resolveRef($repoName, $ref);

        $commitObj = git_commit_lookup($repo, $sha);
        $tree      = git_commit_tree($commitObj);

        $entry = @git_tree_entry_bypath($tree, ltrim($path, '/'));
        if (!$entry) {
            throw new NotFoundHttpException("File '$path' not found at ref '$ref'.");
        }

        $blobOid = git_tree_entry_id($entry);
        $blobObj = git_blob_lookup($repo, $blobOid);
        $content = git_blob_rawcontent($blobObj);
        $size    = git_blob_rawsize($blobObj);

        return [
            'content'   => $content,
            'size'      => $size,
            'is_binary' => (bool) git_blob_is_binary($blobObj),
            'sha'       => $blobOid,
        ];
    }

    /**
     * @return array<string, array{name: string, sha: string, is_head: bool}>
     */
    public function getBranches(string $repoName): array
    {
        $repo = $this->openRepo($repoName);

        $head    = @git_repository_head($repo);
        $headSha = null;
        if ($head) {
            $resolved = @git_reference_resolve($head);
            if ($resolved) {
                $headSha = git_reference_target($resolved);
            }
        }

        $allRefs   = git_reference_list($repo);
        $branches  = [];

        foreach ($allRefs as $refName) {
            if (!str_starts_with($refName, 'refs/heads/')) {
                continue;
            }
            $shortName = substr($refName, strlen('refs/heads/'));
            $ref       = git_reference_lookup($repo, $refName);
            $resolved  = git_reference_resolve($ref);
            $sha       = git_reference_target($resolved);
            $branches[$shortName] = [
                'name'    => $shortName,
                'sha'     => $sha,
                'is_head' => $sha === $headSha,
            ];
        }

        ksort($branches);
        return $branches;
    }

    /**
     * @return array<string, array{name: string, sha: string}>
     */
    public function getTags(string $repoName): array
    {
        $repo    = $this->openRepo($repoName);
        $tagList = git_tag_list($repo) ?? [];
        $tags    = [];

        foreach ($tagList as $name) {
            $obj = @git_revparse_single($repo, $name);
            if (!$obj) continue;

            // For annotated tags (GIT_OBJ_TAG), peel to the target commit.
            // For lightweight tags (already a commit), use the OID directly.
            if (git_object_type($obj) === GIT_OBJ_TAG) {
                try {
                    $targetObj = git_object_peel($obj, GIT_OBJ_COMMIT);
                    $sha = git_object_id($targetObj);
                } catch (\Throwable) {
                    $sha = git_object_id($obj);
                }
            } else {
                $sha = git_object_id($obj);
            }

            $tags[$name] = [
                'name' => $name,
                'sha'  => $sha,
            ];
        }

        krsort($tags);
        return $tags;
    }

    /**
     * One tag, with what a release needs from it: the commit it points at,
     * and - for an annotated tag - its message and date. A lightweight tag
     * has neither; its commit's date stands in.
     *
     * @return array{name: string, sha: string, message: ?string, date: \DateTimeImmutable}
     */
    public function getTag(string $repoName, string $name): array
    {
        $repo = $this->openRepo($repoName);
        try {
            // php-git2 throws on an unknown revspec rather than returning false.
            $obj = git_revparse_single($repo, 'refs/tags/' . $name);
        } catch (\Throwable) {
            $obj = null;
        }
        if (!$obj) {
            throw new NotFoundHttpException("Tag '$name' not found in repository '$repoName'.");
        }

        $message = null;
        $date    = null;
        if (git_object_type($obj) === GIT_OBJ_TAG) {
            $tag     = git_tag_lookup($repo, git_object_id($obj));
            $message = rtrim((string) git_tag_message($tag)) ?: null;
            $tagger  = @git_tag_tagger($tag);
            if ($tagger) {
                $tagger = git2_signature_convert($tagger);
                $date   = new \DateTimeImmutable('@' . $tagger['when.time']);
            }
            $obj = git_object_peel($obj, GIT_OBJ_COMMIT);
        }

        $sha = git_object_id($obj);

        return [
            'name'    => $name,
            'sha'     => $sha,
            'message' => $message,
            'date'    => $date ?? $this->commitInfoFromSha($repo, $sha)->committerDate,
        ];
    }

    /**
     * Every file of the tree at $ref, depth first: path => [content, filemode].
     * What an archive of a release is built from - read from the object
     * database like everything else here, nothing checked out on disk.
     * Submodules are skipped (their content lives in another repository).
     *
     * @return \Generator<string, array{content: string, filemode: int}>
     */
    public function walkTree(string $repoName, string $ref, string $path = ''): \Generator
    {
        $repo = $this->openRepo($repoName);
        $sha  = $this->resolveRef($repoName, $ref);
        $tree = git_commit_tree(git_commit_lookup($repo, $sha));

        if ($path !== '') {
            $entry = @git_tree_entry_bypath($tree, trim($path, '/'));
            if (!$entry) {
                throw new NotFoundHttpException("Path '$path' not found at ref '$ref'.");
            }
            $tree = git_tree_lookup($repo, git_tree_entry_id($entry));
        }

        yield from $this->walk($repo, $tree, $path === '' ? '' : trim($path, '/') . '/');
    }

    private function walk($repo, $tree, string $prefix): \Generator
    {
        $count = git_tree_entrycount($tree);
        for ($i = 0; $i < $count; $i++) {
            $entry = git_tree_entry_byindex($tree, $i);
            $name  = git_tree_entry_name($entry);
            $oid   = git_tree_entry_id($entry);

            switch (git_tree_entry_type($entry)) {
                case GIT_OBJ_TREE:
                    yield from $this->walk($repo, git_tree_lookup($repo, $oid), $prefix . $name . '/');
                    break;
                case GIT_OBJ_BLOB:
                    yield $prefix . $name => [
                        'content'  => (string) git_blob_rawcontent(git_blob_lookup($repo, $oid)),
                        'filemode' => (int) git_tree_entry_filemode($entry),
                    ];
                    break;
            }
        }
    }

    /**
     * Return breadcrumb parts for a path string.
     */
    public function pathBreadcrumbs(string $path): array
    {
        if ($path === '') return [];
        $parts      = explode('/', trim($path, '/'));
        $crumbs     = [];
        $cumulative = '';
        foreach ($parts as $part) {
            $cumulative = $cumulative ? "$cumulative/$part" : $part;
            $crumbs[]   = ['name' => $part, 'path' => $cumulative];
        }
        return $crumbs;
    }

    private function commitInfoFromSha($repo, string $sha, array $refs = []): CommitInfo
    {
        $commit    = git_commit_lookup($repo, $sha);
        $author    = git2_signature_convert(git_commit_author($commit));
        $committer = git2_signature_convert(git_commit_committer($commit));
        $message   = git_commit_message($commit);
        $subject   = explode("\n", $message, 2)[0];

        $parents      = [];
        $parentCount  = git_commit_parentcount($commit);
        for ($i = 0; $i < $parentCount; $i++) {
            $parents[] = git_commit_parent_id($commit, $i);
        }

        return new CommitInfo(
            sha:           $sha,
            shortSha:      substr($sha, 0, 8),
            message:       rtrim($message),
            subject:       rtrim($subject),
            authorName:    $author['name'],
            authorEmail:   $author['email'],
            authorDate:    new \DateTimeImmutable('@' . $author['when.time']),
            committerName: $committer['name'],
            committerEmail:$committer['email'],
            committerDate: new \DateTimeImmutable('@' . $committer['when.time']),
            parentShas:    $parents,
            refs:          $refs,
        );
    }
}
