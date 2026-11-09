<?php

namespace Git\Service;

use Git\Engine\Git;
use Git\Engine\GitException;
use Git\Engine\GitRepository;
use Git\Engine\Model\Commit;
use Git\Engine\Names;
use Git\Model\CommitInfo;
use Git\Model\TreeEntry;
use Git\Repository\RepositoryRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The viewer on the git binary (git.backend: cli, the default): every read
 * goes through Git\Engine\GitRepository - symfony/process, argument arrays,
 * every ref and path checked by Git\Engine\Names. Same answers as
 * Git2Service, without the php-git2 extension.
 */
class CliService implements RepositoryViewerInterface
{
    private RepositoryRegistry $registry;

    /** @var array<string, GitRepository> */
    private array $opened = [];

    /**
     * @param RepositoryRegistry|array<string, array{path: string, label: string|null, description: string|null, default_branch: string}> $repositories
     */
    public function __construct(RepositoryRegistry|array $repositories, private readonly Git $git = new Git('git'))
    {
        $this->registry = \is_array($repositories) ? new RepositoryRegistry($repositories) : $repositories;
    }

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

    public function resolveRef(string $repoName, string $ref): string
    {
        try {
            $sha = $this->open($repoName)->resolve($ref);
        } catch (GitException) {
            $sha = null;
        }

        return $sha ?? throw new NotFoundHttpException("Ref '$ref' not found in repository '$repoName'.");
    }

    public function defaultRef(string $repoName): string
    {
        return $this->resolveRef($repoName, $this->getRepositoryConfig($repoName)['default_branch']);
    }

    public function getCommitLog(string $repoName, string $ref, int $limit = 30, int $offset = 0): array
    {
        $sha = $this->resolveRef($repoName, $ref);

        return array_map(fn (Commit $c): CommitInfo => $this->info($c), $this->open($repoName)->log($sha, '', $limit, $offset));
    }

    public function getCommitGraph(string $repoName, int $limit = 200, int $offset = 0): array
    {
        $refs = $this->getReferencesByCommit($repoName);
        if (!$refs) {
            return [];
        }

        return array_map(fn (Commit $c): CommitInfo => $this->info($c, $refs[$c->id] ?? []), $this->open($repoName)->history(array_map('strval', array_keys($refs)), max(1, $limit), $offset));
    }

    public function getReferencesByCommit(string $repoName): array
    {
        $repository = $this->open($repoName);
        $head = $repository->headBranch();
        $refs = [];
        foreach ($repository->references() as $ref) {
            $name = $ref['name'];
            $sha = $ref['peeled'] ?? $ref['id'];
            if (str_starts_with($name, 'refs/heads/')) {
                $refs[$sha][] = ['type' => 'branch', 'name' => substr($name, 11), 'head' => substr($name, 11) === $head];
            } elseif (str_starts_with($name, 'refs/remotes/') && !str_ends_with($name, '/HEAD')) {
                $refs[$sha][] = ['type' => 'remote', 'name' => substr($name, 13)];
            }
        }
        foreach ($this->getTags($repoName) as $tag) {
            $refs[$tag['sha']][] = ['type' => 'tag', 'name' => $tag['name']];
        }
        if (null === $head && null !== ($detached = $repository->resolve('HEAD'))) {
            $refs[$detached][] = ['type' => 'head', 'name' => 'HEAD'];
        }

        return $refs;
    }

    public function getCommit(string $repoName, string $sha): CommitInfo
    {
        $sha = $this->resolveRef($repoName, $sha);
        $repository = $this->open($repoName);
        $commit = $repository->commit($sha) ?? throw new NotFoundHttpException("Commit '$sha' not found.");
        $patch = $repository->patch($commit->parents[0] ?? $repository->emptyTreeId(), $commit->id);

        return $this->info($commit, [], $patch['patch'], [
            'files_changed' => $patch['files'],
            'insertions' => $patch['insertions'],
            'deletions' => $patch['deletions'],
        ]);
    }

    public function getTree(string $repoName, string $ref, string $path = ''): array
    {
        $sha = $this->resolveRef($repoName, $ref);
        try {
            $entries = $this->open($repoName)->tree($sha, ltrim($path, '/'));
        } catch (GitException) {
            $entries = null;
        }
        if (null === $entries) {
            throw new NotFoundHttpException("Path '$path' not found at ref '$ref'.");
        }
        $tree = array_map(static fn ($e): TreeEntry => new TreeEntry(
            name: $e->name,
            type: match ($e->type) {
                'tree' => TreeEntry::TYPE_TREE,
                'blob' => TreeEntry::TYPE_BLOB,
                default => TreeEntry::TYPE_SUBMODULE,
            },
            sha: $e->id,
            filemode: (int) octdec($e->mode),
        ), $entries);
        usort($tree, static fn (TreeEntry $a, TreeEntry $b): int => $a->isTree() !== $b->isTree() ? ($a->isTree() ? -1 : 1) : strcmp($a->name, $b->name));

        return $tree;
    }

    public function getBlob(string $repoName, string $ref, string $path): array
    {
        $sha = $this->resolveRef($repoName, $ref);
        try {
            $blob = $this->open($repoName)->blob($sha, ltrim($path, '/'), GitRepository::MAX_BLOB);
        } catch (GitException) {
            $blob = null;
        }
        if (null === $blob) {
            throw new NotFoundHttpException("File '$path' not found at ref '$ref'.");
        }

        return ['content' => $blob->content, 'size' => $blob->size, 'is_binary' => $blob->isBinary(), 'sha' => $blob->id];
    }

    public function getBranches(string $repoName): array
    {
        $repository = $this->open($repoName);
        $headSha = $repository->resolve('HEAD');
        $branches = [];
        foreach ($repository->branches() as $ref) {
            $branches[$ref->name] = ['name' => $ref->name, 'sha' => $ref->target, 'is_head' => $ref->target === $headSha];
        }
        ksort($branches);

        return $branches;
    }

    public function getTags(string $repoName): array
    {
        $tags = [];
        foreach ($this->open($repoName)->tags() as $ref) {
            $tags[$ref->name] = ['name' => $ref->name, 'sha' => $ref->target];
        }
        krsort($tags);

        return $tags;
    }

    public function getTag(string $repoName, string $name): array
    {
        try {
            $tag = Names::isRefName($name) ? $this->open($repoName)->tag($name) : null;
        } catch (GitException) {
            $tag = null;
        }
        if (null === $tag) {
            throw new NotFoundHttpException("Tag '$name' not found in repository '$repoName'.");
        }

        return [
            'name' => $name,
            'sha' => $tag['commit'],
            'message' => $tag['message'],
            'date' => $tag['date'] ?? ($this->open($repoName)->commit($tag['commit'])?->committer->date ?? new \DateTimeImmutable()),
        ];
    }

    public function walkTree(string $repoName, string $ref, string $path = ''): \Generator
    {
        $sha = $this->resolveRef($repoName, $ref);
        $repository = $this->open($repoName);
        $path = trim($path, '/');
        if ('' !== $path && 'tree' !== $repository->typeOf($sha, $path)) {
            throw new NotFoundHttpException("Path '$path' not found at ref '$ref'.");
        }
        foreach ($repository->files($sha, $path) as $file) {
            yield $file['path'] => ['content' => $repository->readBlob($file['id']), 'filemode' => (int) octdec($file['mode'])];
        }
    }

    public function pathBreadcrumbs(string $path): array
    {
        if ('' === $path) {
            return [];
        }
        $crumbs = [];
        $cumulative = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $cumulative = '' !== $cumulative ? "$cumulative/$part" : $part;
            $crumbs[] = ['name' => $part, 'path' => $cumulative];
        }

        return $crumbs;
    }

    /** The repository a name stands for; a work tree's own .git, or the bare repository itself. */
    private function open(string $name): GitRepository
    {
        if (!isset($this->opened[$name])) {
            $path = rtrim($this->getRepositoryConfig($name)['path'], '/');
            $this->opened[$name] = new GitRepository($this->git, is_dir($path.'/.git') ? $path.'/.git' : $path);
        }

        return $this->opened[$name];
    }

    private function info(Commit $commit, array $refs = [], ?string $diff = null, array $stats = []): CommitInfo
    {
        $message = rtrim($commit->subject.("" !== $commit->body ? "\n\n".$commit->body : ''));

        return new CommitInfo(
            sha: $commit->id,
            shortSha: substr($commit->id, 0, 8),
            message: $message,
            subject: $commit->subject,
            authorName: $commit->author->name,
            authorEmail: $commit->author->email,
            authorDate: $commit->author->date,
            committerName: $commit->committer->name,
            committerEmail: $commit->committer->email,
            committerDate: $commit->committer->date,
            parentShas: $commit->parents,
            diff: $diff,
            stats: $stats,
            refs: $refs,
        );
    }
}
