<?php

namespace Tests\Git\Engine;

use Git\Engine\Git;
use Git\Engine\Model\Person;
use Git\Engine\RepositoryStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** The git layer on its own (no kernel, no database): write, read, diff, blame, grep, merge. */
final class GitRepositoryTest extends TestCase
{
    private string $root;
    private RepositoryStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/git-engine-unit-'.bin2hex(random_bytes(4));
        $this->store = new RepositoryStore(new Git('git'), $this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testWritingReadingAndMerging(): void
    {
        $git = $this->store->create('1');
        self::assertTrue($git->isEmpty());
        $by = new Person('ada', 'ada@example.org', new \DateTimeImmutable('2026-01-02 03:04:05'));
        $first = $git->commitFiles('main', ['README.md' => "# A\n", 'src/a.txt' => "one\ntwo\n"], 'First', $by);
        self::assertFalse($git->isEmpty());
        self::assertSame('main', $git->headBranch());
        self::assertSame(['src', 'README.md'], array_map(static fn ($e) => $e->name, $git->tree('main')));
        self::assertSame("one\ntwo\n", $git->blob('main', 'src/a.txt')->content);
        self::assertNull($git->blob('main', 'nope.txt'));
        self::assertSame('tree', $git->typeOf('main', 'src'));

        $git->createBranch('topic/x', 'refs/heads/main');
        $git->commitFiles('topic/x', ['src/b.txt' => "b\n"], 'Add b', $by);
        $git->commitFiles('main', ['src/a.txt' => "one\ntwo\nthree\n"], 'Three', $by);
        self::assertSame(['topic/x', 'src'], $git->splitRevisionAndPath('topic/x/src'));

        $diff = $git->diff('refs/heads/main', 'refs/heads/topic/x');
        self::assertCount(1, $diff->files);
        self::assertSame('added', $diff->files[0]->status);
        self::assertSame(1, $diff->additions());

        $blame = $git->blame('main', 'src/a.txt');
        self::assertCount(3, $blame);
        self::assertSame('ada', $blame[2]->author);
        self::assertSame($first, $blame[0]->commit);

        $matches = $git->grep('main', 'THREE');
        self::assertCount(1, $matches);
        self::assertSame(['src/a.txt', 3], [$matches[0]->path, $matches[0]->line]);

        $result = $git->merge('main', 'refs/heads/topic/x', "Merge topic/x\n", $by);
        self::assertTrue($result->isMerged());
        self::assertCount(2, $git->commit('main')->parents);
        self::assertSame("b\n", $git->blob('main', 'src/b.txt')->content);
        self::assertTrue($git->merge('main', 'refs/heads/topic/x', 'again', $by)->alreadyMerged);
        self::assertGreaterThan(0, $git->sizeBytes());
    }

    public function testAConflictIsReportedAndMovesNothing(): void
    {
        $git = $this->store->create('2');
        $by = new Person('ada', 'ada@example.org', new \DateTimeImmutable());
        $git->commitFiles('main', ['f' => "1\n"], '1', $by);
        $git->createBranch('other', 'refs/heads/main');
        $git->commitFiles('other', ['f' => "2\n"], '2', $by);
        $tip = $git->commitFiles('main', ['f' => "3\n"], '3', $by);
        $result = $git->merge('main', 'refs/heads/other', 'merge', $by);
        self::assertFalse($result->isMerged());
        self::assertSame(['f'], $result->conflicts);
        self::assertSame($tip, $git->resolve('refs/heads/main'));
    }

    public function testTheStoreNamesDirectoriesByKeyOnly(): void
    {
        self::assertSame($this->root.'/42.git', $this->store->pathOf('42'));
        $this->expectException(\Git\Engine\GitException::class);
        $this->store->pathOf('../etc');
    }
}
