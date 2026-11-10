<?php

namespace Git\Engine;

use Git\Engine\Model\BlameLine;
use Git\Engine\Model\Blob;
use Git\Engine\Model\Commit;
use Git\Engine\Model\Diff;
use Git\Engine\Model\GrepMatch;
use Git\Engine\Model\MergeResult;
use Git\Engine\Model\Person;
use Git\Engine\Model\Ref;
use Git\Engine\Model\TreeEntry;

/**
 * One bare repository on the disk, read and written through the git binary
 * (Git\Engine\Git). It knows nothing of accounts, rights or the database: the
 * caller checks who may ask, this checks what is asked (Git\Engine\Names).
 */
class GitRepository
{
    private const LOG_FORMAT = '%H%x00%P%x00%an%x00%ae%x00%aI%x00%cn%x00%ce%x00%cI%x00%s%x00%b%x1e';

    public const MAX_BLOB = 64 * 1024 * 1024;

    private ?string $emptyTree = null;

    public function __construct(
        private readonly Git $git,
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path.'/HEAD');
    }

    // ── Creating ───────────────────────────────────────────────────────

    /**
     * @param string|null $shared git init --shared: "group" when more than one
     *                            system user writes the repositories (a web server
     *                            and an SSH server's git user), null for git's default
     */
    public function init(string $defaultBranch = 'main', ?string $shared = null): void
    {
        Names::refName($defaultBranch);
        if (null !== $shared && !preg_match('/^(?:false|true|umask|group|all|world|everybody|0[0-7]{3})$/', $shared)) {
            throw GitException::invalid('shared mode', $shared);
        }
        if ($this->exists()) {
            throw new GitException('The repository exists already.');
        }
        $this->git->run(null, ['init', '--bare', '--quiet', '--initial-branch='.$defaultBranch, ...(null !== $shared ? ['--shared='.$shared] : []), $this->path]);
        foreach ([
            // Pushes are checked by the caller (who may push) before git sees them: HttpBackend serves them.
            'http.receivepack' => 'true',
            'http.uploadpack' => 'true',
            'receive.fsckObjects' => 'true',
            'receive.denyDeleteCurrent' => 'true',
            'core.logAllRefUpdates' => 'true',
            'uploadpack.allowFilter' => 'true',
            'gc.auto' => '0',
        ] as $key => $value) {
            $this->config($key, $value);
        }
    }

    public function config(string $key, string $value): void
    {
        if (!preg_match('/^[a-z]+(\.[A-Za-z0-9-]+)+$/', $key)) {
            throw GitException::invalid('config key', $key);
        }
        $this->git->run($this->path, ['config', $key, $value]);
    }

    // ── Refs ───────────────────────────────────────────────────────────

    public function isEmpty(): bool
    {
        return '' === trim((string) $this->git->run($this->path, ['for-each-ref', '--count=1', '--format=%(refname)']));
    }

    public function headBranch(): ?string
    {
        $out = $this->git->run($this->path, ['symbolic-ref', '--quiet', '--short', 'HEAD'], allowedExits: [1, 128]);

        return null === $out ? null : trim($out);
    }

    public function setHeadBranch(string $branch): void
    {
        $this->git->run($this->path, ['symbolic-ref', 'HEAD', 'refs/heads/'.Names::refName($branch)]);
    }

    /** @return list<Ref> the branches, the most recently committed first */
    public function branches(): array
    {
        return $this->refs('refs/heads/', '%(committerdate:iso-strict)');
    }

    /** @return list<Ref> the tags, the newest first; their target is the commit they name */
    public function tags(): array
    {
        return $this->refs('refs/tags/', '%(creatordate:iso-strict)');
    }

    /** @return list<Ref> */
    private function refs(string $prefix, string $dateField): array
    {
        $out = (string) $this->git->run($this->path, ['for-each-ref', '--sort=-'.trim($dateField, '%()'), '--format=%(refname)%00%(objectname)%00%(*objectname)%00'.$dateField.'%00%(contents:subject)', $prefix]);
        $refs = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$full, $id, $peeled, $date, $subject] = array_pad(explode("\0", $line), 5, '');
            $refs[] = new Ref(substr($full, \strlen($prefix)), $full, '' !== $peeled ? $peeled : $id, '' !== $date ? new \DateTimeImmutable($date) : null, $subject);
        }

        return $refs;
    }

    /** @return array<string, string> every ref and its object id: what a push changes is told by two of these. */
    public function snapshot(): array
    {
        $out = (string) $this->git->run($this->path, ['for-each-ref', '--format=%(refname)%00%(objectname)']);
        $refs = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $id] = explode("\0", $line);
            $refs[$name] = $id;
        }

        return $refs;
    }

    /** The commit a revision names, or null. */
    public function resolve(string $revision): ?string
    {
        Names::revision($revision);
        $out = $this->git->run($this->path, ['rev-parse', '--verify', '--quiet', $revision.'^{commit}'], allowedExits: [1, 128]);

        return null === $out ? null : trim($out);
    }

    public function hasBranch(string $branch): bool
    {
        return Names::isRefName($branch) && null !== $this->git->run($this->path, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], allowedExits: [1]);
    }

    public function createBranch(string $branch, string $from): void
    {
        $sha = $this->resolve($from) ?? throw new GitException('Unknown revision.');
        $this->git->run($this->path, ['update-ref', 'refs/heads/'.Names::refName($branch), $sha, str_repeat('0', \strlen($sha))]);
    }

    public function createTag(string $tag, string $from): void
    {
        $sha = $this->resolve($from) ?? throw new GitException('Unknown revision.');
        $this->git->run($this->path, ['update-ref', 'refs/tags/'.Names::refName($tag), $sha, str_repeat('0', \strlen($sha))]);
    }

    /**
     * Splits what follows /tree/ or /blob/ in an address ("feature/x/src/a.php")
     * into a revision and a path, trying the longest branch or tag first,
     * then an object id. Null when nothing matches.
     *
     * @return array{string, string}|null [revision, path]
     */
    public function splitRevisionAndPath(string $rest): ?array
    {
        $rest = trim($rest, '/');
        $names = array_map(static fn (Ref $r): string => $r->name, [...$this->branches(), ...$this->tags()]);
        usort($names, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($names as $name) {
            if ($rest === $name || str_starts_with($rest, $name.'/')) {
                return [$name, (string) substr($rest, \strlen($name) + 1)];
            }
        }
        $first = explode('/', $rest, 2);
        if (Names::isObjectId($first[0]) && null !== $this->resolve($first[0])) {
            return [$first[0], $first[1] ?? ''];
        }

        return null;
    }

    // ── Trees and files ────────────────────────────────────────────────

    /** @return list<TreeEntry>|null the folder at $path, folders first; null when it is no folder */
    public function tree(string $commit, string $path = ''): ?array
    {
        $path = Names::path($path);
        $treeish = Names::revision($commit).('' === $path ? '^{tree}' : ':'.$path);
        $out = $this->git->run($this->path, ['ls-tree', '-z', '--long', $treeish], allowedExits: [128]);
        if (null === $out) {
            return null;
        }
        $entries = [];
        foreach (array_filter(explode("\0", $out), 'strlen') as $record) {
            [$meta, $name] = explode("\t", $record, 2);
            $parts = preg_split('/\s+/', trim($meta));
            [$mode, $type, $id] = $parts;
            $size = isset($parts[3]) && '-' !== $parts[3] ? (int) $parts[3] : null;
            $entries[] = new TreeEntry($mode, $type, $id, $name, ('' === $path ? '' : $path.'/').$name, $size);
        }
        usort($entries, static fn (TreeEntry $a, TreeEntry $b): int => ($b->isTree() <=> $a->isTree()) ?: strcasecmp($a->name, $b->name));

        return $entries;
    }

    /**
     * The last commit that touched each path, as a folder's table shows it
     * (one `git log -1` per entry, up to $max entries; the others get none).
     *
     * @param list<string> $paths
     *
     * @return array<string, Commit>
     */
    public function lastCommits(string $commit, array $paths, int $max = 80): array
    {
        $commit = Names::revision($commit);
        $last = [];
        foreach (\array_slice($paths, 0, $max) as $path) {
            $path = Names::path($path);
            $found = $this->parseLog((string) $this->git->run($this->path, ['log', '-1', '--format='.self::LOG_FORMAT, $commit, '--', $path]));
            if (isset($found[0])) {
                $last[$path] = $found[0];
            }
        }

        return $last;
    }

    /** What $path is at $commit: "blob", "tree", "commit" (a submodule) or null. */
    public function typeOf(string $commit, string $path): ?string
    {
        $path = Names::path($path);
        if ('' === $path) {
            return 'tree';
        }
        $out = $this->git->run($this->path, ['cat-file', '-t', Names::revision($commit).':'.$path], allowedExits: [128]);

        return null === $out ? null : trim($out);
    }

    /** A file at a revision, its first $maxBytes. */
    public function blob(string $commit, string $path, int $maxBytes = 1048576): ?Blob
    {
        $path = Names::path($path);
        $spec = Names::revision($commit).':'.$path;
        $check = trim((string) $this->git->run($this->path, ['cat-file', '--batch-check'], $spec."\n"));
        $parts = explode(' ', $check);
        if (3 !== \count($parts) || 'blob' !== $parts[1]) {
            return null;
        }
        [$id, , $size] = $parts;
        [$content, $truncated] = $this->readLimited(['cat-file', 'blob', $id], $maxBytes);

        return new Blob($id, $path, (int) $size, $content, $truncated);
    }

    /** @return list<GrepMatch> lines of the tree at $commit holding $needle (a fixed string, case-insensitive) */
    public function grep(string $commit, string $needle, int $limit = 200): array
    {
        if ('' === trim($needle) || \strlen($needle) > 200 || str_contains($needle, "\0")) {
            return [];
        }
        $commit = Names::revision($commit);
        $out = $this->git->run($this->path, ['grep', '--null', '-n', '-I', '-i', '--fixed-strings', '--max-count=20', '-e', $needle, $commit, '--'], allowedExits: [1], maxOutput: 4 * 1024 * 1024);
        $matches = [];
        foreach (explode("\n", (string) $out) as $line) {
            if ('' === $line) {
                continue;
            }
            $fields = explode("\0", $line, 3);
            if (3 !== \count($fields)) {
                continue;
            }
            [$where, $number, $text] = $fields;
            $matches[] = new GrepMatch((string) substr($where, \strlen($commit) + 1), (int) $number, mb_strimwidth($text, 0, 300, '…'));
            if (\count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /** @return list<BlameLine> */
    public function blame(string $commit, string $path, int $maxLines = 5000): array
    {
        $path = Names::path($path);
        $out = (string) $this->git->run($this->path, ['blame', '--porcelain', Names::revision($commit), '--', $path], maxOutput: 8 * 1024 * 1024);
        $lines = [];
        $info = [];
        $current = null;
        $number = 0;
        $previous = null;
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^([0-9a-f]{40,64}) \d+ (\d+)/', $line, $m)) {
                $current = $m[1];
                $number = (int) $m[2];
                $info[$current] ??= [];
                continue;
            }
            if (null === $current) {
                continue;
            }
            if (str_starts_with($line, "\t")) {
                $i = $info[$current];
                $lines[] = new BlameLine($number, $current, $i['author'] ?? '', (new \DateTimeImmutable('@'.($i['author-time'] ?? 0))), $i['summary'] ?? '', substr($line, 1), $previous !== $current);
                $previous = $current;
                if (\count($lines) >= $maxLines) {
                    break;
                }
                continue;
            }
            [$key, $value] = array_pad(explode(' ', $line, 2), 2, '');
            if (\in_array($key, ['author', 'author-time', 'summary'], true)) {
                $info[$current][$key] = $value;
            }
        }

        return $lines;
    }

    // ── History ────────────────────────────────────────────────────────

    /** @return list<Commit> */
    public function log(string $revision, string $path = '', int $limit = 30, int $skip = 0): array
    {
        $args = ['log', '--format='.self::LOG_FORMAT, '-n', (string) max(1, min($limit, 500)), '--skip='.max(0, $skip), Names::revision($revision), '--'];
        if ('' !== ($path = Names::path($path))) {
            $args[] = $path;
        }

        return $this->parseLog((string) $this->git->run($this->path, $args));
    }

    public function countCommits(string $revision): int
    {
        return (int) trim((string) $this->git->run($this->path, ['rev-list', '--count', Names::revision($revision), '--']));
    }

    /**
     * The history of several tips at once, children before their parents
     * (--topo-order), as a graph is drawn from it.
     *
     * @param list<string> $tips object ids or ref names
     *
     * @return list<Commit>
     */
    public function history(array $tips, int $limit = 200, int $skip = 0): array
    {
        if ([] === $tips) {
            return [];
        }
        $args = ['log', '--format='.self::LOG_FORMAT, '--topo-order', '-n', (string) max(1, $limit), '--skip='.max(0, $skip)];
        foreach ($tips as $tip) {
            $args[] = Names::revision($tip);
        }
        $args[] = '--';

        return $this->parseLog((string) $this->git->run($this->path, $args));
    }

    /**
     * Every ref - branches, tags, remote branches, the symbolic ones aside -
     * with the object it names and, for an annotated tag, the commit it tags.
     *
     * @return list<array{name: string, id: string, peeled: ?string, type: string}>
     */
    public function references(): array
    {
        $out = (string) $this->git->run($this->path, ['for-each-ref', '--format=%(refname)%00%(objectname)%00%(*objectname)%00%(objecttype)%00%(symref)']);
        $refs = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $id, $peeled, $type, $symref] = array_pad(explode("\0", $line), 5, '');
            if ('' !== $symref) {
                continue;
            }
            $refs[] = ['name' => $name, 'id' => $id, 'peeled' => '' !== $peeled ? $peeled : null, 'type' => $type];
        }

        return $refs;
    }

    /** @return array{id: string, commit: string, message: ?string, date: ?\DateTimeImmutable}|null an annotated tag's message and date; a lightweight tag has neither */
    public function tag(string $name): ?array
    {
        $out = $this->git->run($this->path, ['for-each-ref', '--format=%(objecttype)%00%(objectname)%00%(*objectname)%00%(taggerdate:iso-strict)%00%(contents)', 'refs/tags/'.Names::refName($name)]);
        if (null === $out || '' === trim($out)) {
            return null;
        }
        [$type, $id, $peeled, $date, $contents] = array_pad(explode("\0", rtrim($out, "\n"), 5), 5, '');
        $annotated = 'tag' === $type;

        return [
            'id' => $id,
            'commit' => $annotated && '' !== $peeled ? $peeled : $id,
            'message' => $annotated ? (rtrim($contents) ?: null) : null,
            'date' => $annotated && '' !== $date ? new \DateTimeImmutable($date) : null,
        ];
    }

    /**
     * Every file under $path at $commit, depth first, as `ls-tree -r` lists
     * them (submodules aside).
     *
     * @return list<array{path: string, id: string, mode: string}>
     */
    public function files(string $commit, string $path = ''): array
    {
        $path = Names::path($path);
        $out = $this->git->run($this->path, ['ls-tree', '-r', '-z', Names::revision($commit).('' === $path ? '^{tree}' : ':'.$path)], allowedExits: [128]);
        if (null === $out) {
            return [];
        }
        $files = [];
        foreach (array_filter(explode("\0", $out), 'strlen') as $record) {
            [$meta, $name] = explode("\t", $record, 2);
            [$mode, $type, $id] = preg_split('/\s+/', trim($meta));
            if ('blob' === $type) {
                $files[] = ['path' => ('' === $path ? '' : $path.'/').$name, 'id' => $id, 'mode' => $mode];
            }
        }

        return $files;
    }

    /** A blob's content by its id, whole. */
    public function readBlob(string $id, int $maxBytes = self::MAX_BLOB): string
    {
        if (!Names::isObjectId($id)) {
            throw GitException::invalid('object id', $id);
        }

        return (string) $this->git->run($this->path, ['cat-file', 'blob', $id], maxOutput: $maxBytes);
    }

    /**
     * The unified diff from $base to $head as git prints it, and its stats.
     *
     * @return array{patch: string, files: int, insertions: int, deletions: int, truncated: bool}
     */
    public function patch(string $base, string $head, int $maxBytes = 2097152): array
    {
        [$text, $truncated] = $this->readLimited(['-c', 'core.quotePath=false', 'diff', '--no-color', '--no-ext-diff', '--find-renames', '--no-textconv', Names::revision($base), Names::revision($head), '--'], $maxBytes);
        $diff = (new DiffParser())->parse($text, $truncated);

        return ['patch' => $text, 'files' => \count($diff->files), 'insertions' => $diff->additions(), 'deletions' => $diff->deletions(), 'truncated' => $truncated];
    }

    /** The tree with nothing in it, the parent a first commit is compared with. */
    public function emptyTreeId(): string
    {
        return $this->emptyTree();
    }

    /** @return list<Commit> the commits of $head that $base has not, the oldest first */
    public function commitsBetween(string $base, string $head, int $limit = 250): array
    {
        $out = (string) $this->git->run($this->path, ['log', '--format='.self::LOG_FORMAT, '--reverse', '-n', (string) $limit, Names::revision($base).'..'.Names::revision($head), '--']);

        return $this->parseLog($out);
    }

    public function commit(string $id): ?Commit
    {
        if (null === ($sha = $this->resolve($id))) {
            return null;
        }

        return $this->parseLog((string) $this->git->run($this->path, ['show', '-s', '--format='.self::LOG_FORMAT, $sha]))[0] ?? null;
    }

    /** @return list<Commit> */
    private function parseLog(string $out): array
    {
        $commits = [];
        foreach (explode("\x1e", $out) as $record) {
            $record = ltrim($record, "\n");
            if ('' === $record) {
                continue;
            }
            $f = explode("\0", $record);
            if (\count($f) < 10) {
                continue;
            }
            $commits[] = new Commit(
                $f[0],
                array_values(array_filter(explode(' ', $f[1]))),
                new Person($f[2], $f[3], new \DateTimeImmutable($f[4])),
                new Person($f[5], $f[6], new \DateTimeImmutable($f[7])),
                $f[8],
                rtrim($f[9]),
            );
        }

        return $commits;
    }

    // ── Diffs ──────────────────────────────────────────────────────────

    /** What a commit changed against its first parent (against nothing for the first commit). */
    public function commitDiff(string $id, int $maxBytes = 2097152): Diff
    {
        $commit = $this->commit($id) ?? throw new GitException('Unknown commit.');

        return $this->diff($commit->parents[0] ?? $this->emptyTree(), $commit->id, $maxBytes, false);
    }

    /**
     * The diff from $base to $head; with $threeDots, from where they parted
     * (what a pull request brings).
     */
    public function diff(string $base, string $head, int $maxBytes = 2097152, bool $threeDots = true): Diff
    {
        $range = $threeDots ? Names::revision($base).'...'.Names::revision($head) : null;
        $args = ['-c', 'core.quotePath=false', 'diff', '--no-color', '--no-ext-diff', '--find-renames', '--no-textconv'];
        $args = [...$args, ...($range ? [$range] : [Names::revision($base), Names::revision($head)]), '--'];
        [$text, $truncated] = $this->readLimited($args, $maxBytes);

        return (new DiffParser())->parse($text, $truncated);
    }

    public function mergeBase(string $a, string $b): ?string
    {
        $out = $this->git->run($this->path, ['merge-base', Names::revision($a), Names::revision($b)], allowedExits: [1]);

        return null === $out ? null : trim($out);
    }

    public function isAncestor(string $ancestor, string $of): bool
    {
        return null !== $this->git->run($this->path, ['merge-base', '--is-ancestor', Names::revision($ancestor), Names::revision($of)], allowedExits: [1]);
    }

    private function emptyTree(): string
    {
        return $this->emptyTree ??= trim((string) $this->git->run($this->path, ['hash-object', '-t', 'tree', '--stdin'], ''));
    }

    // ── Writing ────────────────────────────────────────────────────────

    /**
     * Merges $head into the branch $base on the server, without a work tree:
     * git's own merge (merge-tree, the "ort" strategy of `git merge`), a merge
     * commit with both parents, then the branch moved only if it still points
     * where it pointed when the merge began (update-ref's old value). The
     * caller holds a lock for the repository (Git\Engine\RepositoryLocks::merge()
     * takes it).
     */
    public function merge(string $base, string $head, string $message, Person $by): MergeResult
    {
        Names::refName($base);
        $baseSha = $this->resolve('refs/heads/'.$base) ?? throw new GitException('Unknown base branch.');
        $headSha = $this->resolve($head) ?? throw new GitException('Unknown head.');
        if ($this->isAncestor($headSha, $baseSha)) {
            return new MergeResult(null, alreadyMerged: true);
        }
        $process = $this->git->process($this->path, ['merge-tree', '--write-tree', '--name-only', '--no-messages', $baseSha, $headSha]);
        $process->run();
        $lines = array_values(array_filter(explode("\n", $process->getOutput()), 'strlen'));
        if (1 === $process->getExitCode()) {
            return new MergeResult(null, array_values(array_unique(\array_slice($lines, 1))));
        }
        if (0 !== $process->getExitCode()) {
            throw new GitException('The merge failed: '.trim($process->getErrorOutput()));
        }
        $tree = $lines[0] ?? throw new GitException('The merge wrote no tree.');
        $commit = trim((string) $this->git->run($this->path, ['commit-tree', $tree, '-p', $baseSha, '-p', $headSha, '-F', '-'], $message, $this->identity($by)));
        $this->git->run($this->path, ['update-ref', '-m', 'merge', 'refs/heads/'.$base, $commit, $baseSha]);

        return new MergeResult($commit);
    }

    /**
     * Writes files on a branch as one new commit, without a work tree (a
     * temporary index): a way to fill a repository from code, or to save an
     * edit made in a page. The branch is created when it does not exist.
     *
     * @param array<string, string|null> $files path => content (null removes the file)
     */
    public function commitFiles(string $branch, array $files, string $message, Person $by): string
    {
        Names::refName($branch);
        $parent = $this->resolve('refs/heads/'.$branch);
        $index = tempnam(sys_get_temp_dir(), 'git-engine-index-');
        @unlink($index);
        $env = ['GIT_INDEX_FILE' => $index];
        try {
            if (null !== $parent) {
                $this->git->run($this->path, ['read-tree', $parent], env: $env);
            }
            foreach ($files as $path => $content) {
                $path = Names::path((string) $path);
                if ('' === $path) {
                    throw GitException::invalid('path', $path);
                }
                if (null === $content) {
                    $this->git->run($this->path, ['update-index', '--force-remove', '--', $path], env: $env);
                    continue;
                }
                $blob = trim((string) $this->git->run($this->path, ['hash-object', '-w', '--stdin'], $content));
                $this->git->run($this->path, ['update-index', '--add', '--cacheinfo', '100644,'.$blob.','.$path], env: $env);
            }
            $tree = trim((string) $this->git->run($this->path, ['write-tree'], env: $env));
        } finally {
            @unlink($index);
        }
        $args = ['commit-tree', $tree, '-F', '-'];
        if (null !== $parent) {
            array_splice($args, 2, 0, ['-p', $parent]);
        }
        $commit = trim((string) $this->git->run($this->path, $args, $message, $this->identity($by)));
        $this->git->run($this->path, ['update-ref', 'refs/heads/'.$branch, $commit, $parent ?? str_repeat('0', \strlen($commit))]);

        return $commit;
    }

    /** Bytes on the disk: loose objects and packs (git count-objects). */
    public function sizeBytes(): int
    {
        $out = (string) $this->git->run($this->path, ['count-objects', '-v']);
        $kib = 0;
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(size|size-pack|size-garbage): (\d+)$/', $line, $m)) {
                $kib += (int) $m[2];
            }
        }

        return $kib * 1024;
    }

    /** @return array<string, string> */
    private function identity(Person $by): array
    {
        $date = $by->date->format('U O');

        return [
            'GIT_AUTHOR_NAME' => $by->name, 'GIT_AUTHOR_EMAIL' => $by->email, 'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_NAME' => $by->name, 'GIT_COMMITTER_EMAIL' => $by->email, 'GIT_COMMITTER_DATE' => $date,
        ];
    }

    /** @return array{string, bool} the first $maxBytes of a command's output, and whether there was more */
    private function readLimited(array $args, int $maxBytes): array
    {
        $process = $this->git->process($this->path, $args);
        $process->start();
        $out = '';
        $truncated = false;
        foreach ($process->getIterator($process::ITER_SKIP_ERR) as $chunk) {
            $out .= $chunk;
            if (\strlen($out) > $maxBytes) {
                $out = substr($out, 0, $maxBytes);
                $truncated = true;
                $process->stop(0);
                break;
            }
        }
        if (!$truncated) {
            $process->wait();
            if (0 !== $process->getExitCode()) {
                throw new GitException(sprintf('git %s failed: %s', $args[0], trim(mb_strimwidth($process->getErrorOutput(), 0, 500))));
            }
        }

        return [$out, $truncated];
    }
}
