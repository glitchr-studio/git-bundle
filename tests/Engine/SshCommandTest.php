<?php

namespace Tests\Git\Engine;

use Git\Engine\GitException;
use Git\Engine\Names;
use Git\Engine\SshCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshCommandTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testWhatGitSendsIsRead(string $original, string $service, string $path): void
    {
        $command = SshCommand::parse($original);
        self::assertSame($service, $command->service);
        self::assertSame($path, $command->path);
    }

    public static function accepted(): iterable
    {
        yield 'scp-like address' => ["git-upload-pack 'ada/hello.git'", SshCommand::UPLOAD_PACK, 'ada/hello'];
        yield 'ssh:// address' => ["git-upload-pack '/ada/hello.git'", SshCommand::UPLOAD_PACK, 'ada/hello'];
        yield 'without .git' => ["git-receive-pack 'ada/hello'", SshCommand::RECEIVE_PACK, 'ada/hello'];
        yield 'unquoted' => ['git-upload-archive ada/hello.git', SshCommand::UPLOAD_ARCHIVE, 'ada/hello'];
        yield 'the older spelling' => ["git upload-pack 'ada/hello.git'", SshCommand::UPLOAD_PACK, 'ada/hello'];
        yield 'dots, dashes, underscores' => ["git-upload-pack 'my-org/lib_v2.0.git'", SshCommand::UPLOAD_PACK, 'my-org/lib_v2.0'];
        yield 'one segment' => ["git-upload-pack 'demo.git'", SshCommand::UPLOAD_PACK, 'demo'];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $original): void
    {
        $this->expectException(GitException::class);
        SshCommand::parse($original);
    }

    public static function refused(): iterable
    {
        yield 'nothing' => [''];
        yield 'a shell' => ['sh'];
        yield 'another program' => ["ls -la '/'"];
        yield 'a git command not served' => ["git-shell -c 'ada/hello.git'"];
        yield 'git itself' => ["git --exec-path=/tmp upload-pack 'ada/hello.git'"];
        yield 'no path' => ['git-upload-pack'];
        yield 'an empty path' => ["git-upload-pack ''"];
        yield 'two arguments' => ["git-upload-pack 'ada/hello.git' 'other.git'"];
        yield 'two spaces' => ["git-upload-pack  'ada/hello.git'"];
        yield 'an option' => ["git-upload-pack '--help'"];
        yield 'an option before the path' => ["git-upload-pack --strict 'ada/hello.git'"];
        yield 'an option as a segment' => ["git-upload-pack 'ada/--upload-pack=touch.git'"];
        yield 'a dash-led owner' => ["git-receive-pack '-ada/hello.git'"];
        yield 'parent directory' => ["git-upload-pack '../../etc/passwd'"];
        yield 'parent inside' => ["git-upload-pack 'ada/../bob/secret.git'"];
        yield 'current directory' => ["git-upload-pack 'ada/./hello.git'"];
        yield 'absolute' => ["git-upload-pack '//srv/repositories/1.git'"];
        yield 'home' => ["git-upload-pack '~git/1.git'"];
        yield 'an empty segment' => ["git-upload-pack 'ada//hello.git'"];
        yield 'a trailing slash' => ["git-upload-pack 'ada/hello/'"];
        yield 'only .git' => ["git-upload-pack '.git'"];
        yield 'a quote inside' => ["git-upload-pack 'ada/he'\\''llo.git'"];
        yield 'double quotes' => ['git-upload-pack "ada/hello.git"'];
        yield 'a command after' => ["git-upload-pack 'ada/hello.git'; rm -rf /"];
        yield 'a pipe' => ['git-upload-pack ada/hello.git|sh'];
        yield 'a substitution' => ['git-upload-pack $(id)'];
        yield 'a backtick' => ['git-upload-pack `id`'];
        yield 'a newline' => ["git-upload-pack 'ada/hello.git'\nsh"];
        yield 'a NUL' => ["git-upload-pack 'ada/hello.git'\0"];
        yield 'a tab' => ["git-upload-pack\t'ada/hello.git'"];
        yield 'too long' => ["git-upload-pack '".str_repeat('a/', 600)."x'"];
        yield 'too deep' => ["git-upload-pack '".implode('/', range(1, 9))."'"];
        yield 'not UTF-8' => ["git-upload-pack '\xff.git'"];
    }

    public function testAServerMayServeFewerServices(): void
    {
        self::assertSame('ada/hello', SshCommand::parse("git-upload-pack 'ada/hello.git'", [SshCommand::UPLOAD_PACK])->path);
        $this->expectException(GitException::class);
        SshCommand::parse("git-receive-pack 'ada/hello.git'", [SshCommand::UPLOAD_PACK]);
    }

    public function testSegmentsAndWrites(): void
    {
        $push = SshCommand::parse("git-receive-pack 'ada/hello.git'");
        self::assertSame(['ada', 'hello'], $push->segments());
        self::assertTrue($push->isWrite());
        self::assertFalse(SshCommand::parse("git-upload-archive 'ada/hello.git'")->isWrite());
    }

    public function testRepositoryPaths(): void
    {
        self::assertTrue(Names::isRepositoryPath('ada/hello'));
        self::assertTrue(Names::isRepositoryPath('a/b/c', 3));
        self::assertFalse(Names::isRepositoryPath('a/b/c', 2));
        self::assertFalse(Names::isRepositoryPath(''));
        self::assertFalse(Names::isRepositoryPath('ada/hello.git'));
        self::assertFalse(Names::isRepositoryPath('ada/-x'));
    }
}
