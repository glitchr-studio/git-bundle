<?php

namespace Git\Engine;

use Git\Engine\Model\MergeResult;
use Git\Engine\Model\Person;
use Symfony\Component\Lock\LockFactory;

/**
 * One lock per repository (symfony/lock, any store): what moves a branch -
 * a merge made on the server, a push - runs inside it, so that two of them
 * never move the same repository's refs at once. update-ref's old value
 * already refuses a lost race; the lock makes the second one wait instead
 * of failing.
 */
final class RepositoryLocks
{
    public function __construct(
        private readonly LockFactory $locks,
        private readonly float $ttl = 600.0,
        private readonly string $prefix = 'git-repository-',
    ) {
    }

    public function name(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * Runs $work while holding the repository's lock (waiting for it).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(string $key, callable $work): mixed
    {
        $lock = $this->locks->createLock($this->name($key), $this->ttl);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /** Acquires the repository's lock and gives it back, for work that ends later (a streamed push). */
    public function acquire(string $key): \Symfony\Component\Lock\LockInterface
    {
        $lock = $this->locks->createLock($this->name($key), $this->ttl);
        $lock->acquire(true);

        return $lock;
    }

    /** Merges $head into the branch $base of $repository inside its lock (GitRepository::merge()). */
    public function merge(GitRepository $repository, string $key, string $base, string $head, string $message, Person $by): MergeResult
    {
        return $this->run($key, static fn (): MergeResult => $repository->merge($base, $head, $message, $by));
    }
}
