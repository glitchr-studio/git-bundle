<?php

namespace Tests\Git\Engine;

use Git\Engine\GitException;
use Git\Engine\Names;
use PHPUnit\Framework\TestCase;

/** What may reach git: refs, revisions, paths, names. */
final class NamesTest extends TestCase
{
    public function testRefNames(): void
    {
        foreach (['main', 'feature/shout', 'v1.0.0', 'fix-42', 'été'] as $ok) {
            self::assertTrue(Names::isRefName($ok), $ok);
        }
        foreach (['', '-x', '--upload-pack=sh', 'a..b', 'a b', 'a~1', 'a^', 'a:b', 'a?', 'a*', 'a[', 'a\\b', '/a', 'a/', 'a.', 'a.lock', '.hidden', 'a/.b', 'a//b', '@', 'a@{1}', "a\0b", "a\nb"] as $bad) {
            self::assertFalse(Names::isRefName($bad), json_encode($bad));
        }
    }

    public function testPaths(): void
    {
        foreach (['', 'README.md', 'src/a.php', '.gitignore', 'dir/.config/x'] as $ok) {
            self::assertTrue(Names::isPath($ok), $ok);
        }
        foreach (['../etc/passwd', 'a/../b', './a', 'a//b', '-rf', "a\0b", "a/\x01"] as $bad) {
            self::assertFalse(Names::isPath($bad), json_encode($bad));
        }
        self::assertSame('a/b', Names::path('/a/b/'));
        $this->expectException(GitException::class);
        Names::path('../x');
    }

    public function testRevisionsAndRepositoryNames(): void
    {
        self::assertSame('abc1234', Names::revision('abc1234'));
        self::assertSame('main', Names::revision('main'));
        self::assertTrue(Names::isRepositoryName('hello-bud'));
        self::assertTrue(Names::isRepositoryName('a.b_c'));
        foreach (['', '.', '..', 'x.git', 'a b', '-x', str_repeat('a', 101), 'a/b'] as $bad) {
            self::assertFalse(Names::isRepositoryName($bad), $bad);
        }
        $this->expectException(GitException::class);
        Names::revision('--all');
    }
}
