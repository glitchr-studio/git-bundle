<?php

namespace Git\Engine;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Where the bare repositories live: one directory per repository under one
 * root, named by a key the caller gives (an id: "42" -> <root>/42.git), never
 * by a name a visitor chose, so that a rename moves nothing and no name can
 * point outside the root.
 */
class RepositoryStore
{
    /**
     * @param string|null $shared how new repositories are shared between system users
     *                            (git init --shared, GitRepository::init()): "group" when
     *                            a web server and an SSH server's git user both write them
     */
    public function __construct(
        private readonly Git $git,
        private readonly string $root,
        private readonly ?string $shared = null,
    ) {
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public function pathOf(string $key): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $key)) {
            throw GitException::invalid('repository key', $key);
        }

        return $this->root().'/'.$key.'.git';
    }

    public function open(string $key): GitRepository
    {
        return new GitRepository($this->git, $this->pathOf($key));
    }

    public function create(string $key, string $defaultBranch = 'main'): GitRepository
    {
        (new Filesystem())->mkdir($this->root(), 0770);
        $repository = $this->open($key);
        $repository->init($defaultBranch, $this->shared);

        return $repository;
    }

    public function delete(string $key): void
    {
        $path = $this->pathOf($key);
        if (is_dir($path)) {
            (new Filesystem())->remove($path);
        }
    }

    /** Removes every repository of the root (a test or a demonstration starting from nothing). */
    public function purge(): void
    {
        foreach (glob($this->root().'/*.git', \GLOB_ONLYDIR) ?: [] as $dir) {
            (new Filesystem())->remove($dir);
        }
    }
}
