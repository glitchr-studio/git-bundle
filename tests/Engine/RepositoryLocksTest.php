<?php

namespace Tests\Git\Engine;

use Git\Engine\Git;
use Git\Engine\Model\Person;
use Git\Engine\RepositoryLocks;
use Git\Engine\RepositoryStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RepositoryLocksTest extends TestCase
{
    public function testAMergeRunsInsideTheRepositorysLock(): void
    {
        $root = sys_get_temp_dir().'/git-engine-locks-'.bin2hex(random_bytes(4));
        try {
            $repository = (new RepositoryStore(new Git('git'), $root))->create('r');
            $by = new Person('Ada', 'ada@example.org', new \DateTimeImmutable());
            $repository->commitFiles('main', ['a' => "a\n"], 'a', $by);
            $repository->createBranch('topic', 'refs/heads/main');
            $repository->commitFiles('topic', ['b' => "b\n"], 'b', $by);

            $factory = new LockFactory(new InMemoryStore());
            $locks = new RepositoryLocks($factory);
            $seen = null;
            $locks->run('r', static function () use ($factory, $locks, &$seen): void {
                $seen = $factory->createLock($locks->name('r'))->acquire(false);
            });
            self::assertFalse($seen, 'held while the work runs');
            self::assertTrue($factory->createLock($locks->name('r'))->acquire(false), 'given back after');

            $result = (new RepositoryLocks(new LockFactory(new InMemoryStore())))->merge($repository, 'r', 'main', 'refs/heads/topic', 'Merge topic', $by);
            self::assertTrue($result->isMerged());
            self::assertSame("b\n", $repository->blob('main', 'b')->content);
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}
