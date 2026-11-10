<?php

namespace Tests\Git\Engine;

use Git\Engine\Git;
use Git\Engine\GitException;
use Git\Engine\Model\Person;
use Git\Engine\RepositoryStore;
use Git\Engine\SshBackend;
use Git\Engine\SshCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Git over SSH through SshBackend, end to end without an SSH server: the git
 * client of the machine runs tests/Engine/Fixtures/ssh-serve.php as its "ssh"
 * (GIT_SSH_COMMAND), which reads the command as sshd would hand it to a
 * forced command and serves it over its own stdin and stdout.
 */
final class SshBackendTest extends TestCase
{
    private string $root;
    private string $work;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/git-engine-ssh-'.bin2hex(random_bytes(4));
        $this->work = $this->root.'-work';
        (new Filesystem())->mkdir([$this->root, $this->work]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->root, $this->work]);
    }

    public function testCloneThenPushThenFetchThenArchiveOverSsh(): void
    {
        $store = new RepositoryStore(new Git('git'), $this->root);
        $repository = $store->create('demo');
        $first = $repository->commitFiles('main', ['README.md' => "# Demo\n"], 'First', new Person('Ada', 'ada@example.org', new \DateTimeImmutable()));

        $this->git(['clone', '-q', 'git@engine.test:demo.git', 'here']);
        self::assertSame("# Demo\n", file_get_contents($this->work.'/here/README.md'));

        file_put_contents($this->work.'/here/b.txt', "b\n");
        $this->git(['-C', 'here', 'add', 'b.txt']);
        $this->git(['-C', 'here', '-c', 'user.name=Bob', '-c', 'user.email=bob@example.org', 'commit', '-q', '-m', 'Second']);
        $this->git(['-C', 'here', 'push', '-q', 'origin', 'main']);

        $tip = $repository->resolve('refs/heads/main');
        self::assertNotSame($first, $tip);
        self::assertSame([$first], $repository->commit($tip)->parents);

        // Protocol v2 (GIT_PROTOCOL passed on), then the ssh:// form of the address.
        $this->git(['-c', 'protocol.version=2', 'clone', '-q', 'ssh://git@engine.test/demo.git', 'there']);
        self::assertFileExists($this->work.'/there/b.txt');
        $this->git(['-C', 'there', 'fetch', '-q', 'origin']);

        $archive = $this->git(['archive', '--remote=git@engine.test:demo.git', '--format=tar', 'main', 'b.txt']);
        self::assertStringContainsString("b\n", $archive->getOutput());
    }

    public function testAPushLargerThanAllowedIsRefused(): void
    {
        (new RepositoryStore(new Git('git'), $this->root))->create('small');
        $this->git(['init', '-q', '-b', 'main', 'big']);
        file_put_contents($this->work.'/big/noise.bin', random_bytes(1500000));
        $this->git(['-C', 'big', 'add', 'noise.bin']);
        $this->git(['-C', 'big', '-c', 'user.name=Bob', '-c', 'user.email=bob@example.org', 'commit', '-q', '-m', 'Noise']);
        $push = $this->git(['-C', 'big', 'push', 'git@engine.test:small.git', 'main'], false, ['GIT_ENGINE_TEST_MAX_PUSH' => '1000000']);
        self::assertNotSame(0, $push->getExitCode());
        self::assertTrue((new RepositoryStore(new Git('git'), $this->root))->open('small')->isEmpty(), 'nothing landed');
    }

    public function testAForgedCommandRunsNothing(): void
    {
        (new RepositoryStore(new Git('git'), $this->root))->create('demo');
        foreach (["sh -c 'touch {$this->work}/owned'", "git-upload-pack '../demo.git'", "git-upload-pack '--help'", "git-receive-pack 'demo.git' '--force'"] as $forged) {
            $run = $this->git(['clone', '-q', 'git@engine.test:demo.git', 'forged'], false, ['GIT_ENGINE_TEST_COMMAND' => $forged]);
            self::assertNotSame(0, $run->getExitCode(), $forged);
            self::assertStringContainsString('fatal: Invalid', $run->getErrorOutput(), $forged);
        }
        self::assertFileDoesNotExist($this->work.'/owned');
    }

    public function testTheCommandIsAnArgumentArrayOnTheStoresRepository(): void
    {
        $git = new Git('/usr/bin/git');
        $backend = new SshBackend($git, new RepositoryStore($git, '/srv/repositories'), 300.0);
        self::assertSame(['/usr/bin/git', 'upload-pack', '--strict', '--timeout=300', '/srv/repositories/42.git'], $backend->command(SshCommand::parse("git-upload-pack 'ada/hello.git'"), '42'));
        self::assertSame(['/usr/bin/git', 'receive-pack', '/srv/repositories/42.git'], $backend->command(SshCommand::parse("git-receive-pack 'ada/hello.git'"), '42'));
        self::assertSame(['/usr/bin/git', 'upload-archive', '/srv/repositories/42.git'], $backend->command(SshCommand::parse("git-upload-archive 'ada/hello.git'"), '42'));

        $this->expectException(GitException::class);
        $backend->command(SshCommand::parse("git-upload-pack 'ada/hello.git'"), '../42');
    }

    public function testAMissingRepositoryIsNotServed(): void
    {
        $git = new Git('git');
        $backend = new SshBackend($git, new RepositoryStore($git, $this->root));
        $this->expectException(GitException::class);
        $backend->serve(SshCommand::parse("git-upload-pack 'nothing.git'"), 'nothing', \STDIN, \STDOUT, \STDERR);
    }

    public function testRepositoriesCanBeSharedByAGroup(): void
    {
        $repository = (new RepositoryStore(new Git('git'), $this->root, 'group'))->create('shared');
        self::assertSame('1', trim((string) (new Git('git'))->run($repository->path(), ['config', 'core.sharedRepository'])));
        self::assertSame(02000, fileperms($repository->path().'/objects') & 02000, 'new objects keep the group');

        $this->expectException(GitException::class);
        (new RepositoryStore(new Git('git'), $this->root, 'group; rm'))->create('bad');
    }

    private function git(array $args, bool $mustRun = true, array $env = []): Process
    {
        $process = new Process(['git', ...$args], $this->work, $env + [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME' => $this->work,
            'GIT_SSH_COMMAND' => escapeshellarg(\PHP_BINARY).' '.escapeshellarg(__DIR__.'/Fixtures/ssh-serve.php'),
            'GIT_SSH_VARIANT' => 'simple',
            'GIT_ENGINE_TEST_ROOT' => $this->root,
        ]);
        $process->setTimeout(120);
        $mustRun ? $process->mustRun() : $process->run();

        return $process;
    }
}
