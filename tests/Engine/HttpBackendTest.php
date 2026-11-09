<?php

namespace Tests\Git\Engine;

use Git\Engine\Git;
use Git\Engine\Model\Person;
use Git\Engine\RepositoryStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Git's smart HTTP protocol through HttpBackend, end to end: PHP's own server
 * in front of the bridge (tests/Engine/Fixtures/http-router.php), the git
 * client of the machine cloning, pushing and fetching over it.
 */
final class HttpBackendTest extends TestCase
{
    private string $root;
    private string $work;
    private Process $server;
    private int $port;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/git-engine-http-'.bin2hex(random_bytes(4));
        $this->work = $this->root.'-work';
        (new Filesystem())->mkdir([$this->root, $this->work]);
        $this->port = random_int(30000, 39999);
        $this->server = new Process([\PHP_BINARY, '-S', '127.0.0.1:'.$this->port, __DIR__.'/Fixtures/http-router.php'], null, ['GIT_ENGINE_TEST_ROOT' => $this->root, 'GIT_ENGINE_TEST_MAX_PUSH' => '1000000']);
        $this->server->start();
        for ($i = 0; $i < 100; ++$i) {
            if (@fsockopen('127.0.0.1', $this->port)) {
                return;
            }
            usleep(50000);
        }
        self::fail('PHP\'s server did not start: '.$this->server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        $this->server->stop(0);
        (new Filesystem())->remove([$this->root, $this->work]);
    }

    public function testCloneThenPushThenFetchOverHttp(): void
    {
        $store = new RepositoryStore(new Git('git'), $this->root);
        $repository = $store->create('demo');
        $first = $repository->commitFiles('main', ['README.md' => "# Demo\n"], 'First', new Person('Ada', 'ada@example.org', new \DateTimeImmutable()));
        $url = 'http://127.0.0.1:'.$this->port.'/demo.git';

        $this->git(['clone', '-q', $url, 'here']);
        self::assertSame("# Demo\n", file_get_contents($this->work.'/here/README.md'));

        file_put_contents($this->work.'/here/b.txt', "b\n");
        $this->git(['-C', 'here', 'add', 'b.txt']);
        $this->git(['-C', 'here', '-c', 'user.name=Bob', '-c', 'user.email=bob@example.org', 'commit', '-q', '-m', 'Second']);
        $this->git(['-C', 'here', 'push', '-q', 'origin', 'main']);

        $tip = $repository->resolve('refs/heads/main');
        self::assertNotSame($first, $tip);
        self::assertSame("b\n", $repository->blob('main', 'b.txt')->content);
        self::assertSame([$first], $repository->commit($tip)->parents);

        $this->git(['clone', '-q', $url, 'there']);
        self::assertFileExists($this->work.'/there/b.txt');
    }

    public function testAPushLargerThanAllowedIsRefused(): void
    {
        (new RepositoryStore(new Git('git'), $this->root))->create('small');
        $this->git(['init', '-q', '-b', 'main', 'big']);
        file_put_contents($this->work.'/big/noise.bin', random_bytes(1500000));
        $this->git(['-C', 'big', 'add', 'noise.bin']);
        $this->git(['-C', 'big', '-c', 'user.name=Bob', '-c', 'user.email=bob@example.org', 'commit', '-q', '-m', 'Noise']);
        $push = $this->git(['-C', 'big', '-c', 'http.postBuffer=16777216', 'push', 'http://127.0.0.1:'.$this->port.'/small.git', 'main'], false);
        self::assertNotSame(0, $push->getExitCode());
        self::assertTrue((new RepositoryStore(new Git('git'), $this->root))->open('small')->isEmpty(), 'nothing landed');
    }

    public function testOnlyTheSmartProtocolIsServed(): void
    {
        (new RepositoryStore(new Git('git'), $this->root))->create('demo');
        $dumb = @file_get_contents('http://127.0.0.1:'.$this->port.'/demo.git/info/refs');
        self::assertFalse($dumb);
        $smart = (string) file_get_contents('http://127.0.0.1:'.$this->port.'/demo.git/info/refs?service=git-upload-pack');
        self::assertStringStartsWith("001e# service=git-upload-pack\n0000", $smart);
        self::assertSame(1, substr_count($smart, '# service='), 'the advertisement once, its headers not in the body');
    }

    private function git(array $args, bool $mustRun = true): Process
    {
        $process = new Process(['git', ...$args], $this->work, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $this->work]);
        $process->setTimeout(120);
        $mustRun ? $process->mustRun() : $process->run();

        return $process;
    }
}
