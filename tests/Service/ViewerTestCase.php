<?php

namespace Tests\Git\Service;

use Git\Service\RepositoryViewerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Against a real repository, built with the git binary in a temporary
 * directory: two commits, an annotated tag on the first, a lightweight one
 * on the second.
 */
abstract class ViewerTestCase extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/git-bundle-test-'.bin2hex(random_bytes(4));
        mkdir(self::$dir.'/src/deep', 0777, true);

        $env = 'GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=t@example.org GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=t@example.org';
        $git = fn (string $args) => shell_exec(sprintf('cd %s && %s git %s 2>&1', escapeshellarg(self::$dir), $env, $args));

        $git('init -q -b main');
        file_put_contents(self::$dir.'/README.md', "# Fixture\n");
        file_put_contents(self::$dir.'/src/deep/File.php', "<?php // v1\n");
        $git('add -A');
        $git('commit -q -m "First"');
        file_put_contents(self::$dir.'/.tag-message', "First release\n\n- the README\n");
        $git('tag -a v1.0.0 -F .tag-message');
        unlink(self::$dir.'/.tag-message');

        file_put_contents(self::$dir.'/src/deep/File.php', "<?php // v2\n");
        $git('commit -q -am "Second"');
        $git('tag v1.1.0');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dir) && is_dir(self::$dir)) {
            shell_exec('rm -rf '.escapeshellarg(self::$dir));
        }
    }

    /** The backend under test, over the given repositories. */
    abstract protected static function viewer(array $repositories): RepositoryViewerInterface;

    protected function service(): RepositoryViewerInterface
    {
        return static::viewer(['fixture' => ['path' => self::$dir, 'label' => 'Fixture', 'description' => null, 'default_branch' => 'main']]);
    }

    public function testTagsPointAtTheirCommit(): void
    {
        $tags = $this->service()->getTags('fixture');

        self::assertSame(['v1.1.0', 'v1.0.0'], array_keys($tags));
        self::assertSame($this->service()->resolveRef('fixture', 'main'), $tags['v1.1.0']['sha']);
    }

    public function testAnAnnotatedTagCarriesItsMessage(): void
    {
        $tag = $this->service()->getTag('fixture', 'v1.0.0');

        self::assertSame("First release\n\n- the README", $tag['message']);
        self::assertSame($this->service()->resolveRef('fixture', 'v1.0.0'), $tag['sha']);
        self::assertInstanceOf(\DateTimeImmutable::class, $tag['date']);
    }

    public function testALightweightTagHasNoMessage(): void
    {
        $tag = $this->service()->getTag('fixture', 'v1.1.0');

        self::assertNull($tag['message']);
        self::assertSame($this->service()->resolveRef('fixture', 'main'), $tag['sha']);
    }

    public function testWalkTreeYieldsEveryFileAtThatRef(): void
    {
        $files = iterator_to_array($this->service()->walkTree('fixture', 'v1.0.0'));

        ksort($files);
        self::assertSame(['README.md', 'src/deep/File.php'], array_keys($files));
        self::assertSame("<?php // v1\n", $files['src/deep/File.php']['content']);

        $later = iterator_to_array($this->service()->walkTree('fixture', 'v1.1.0', 'src'));
        self::assertSame(['src/deep/File.php'], array_keys($later));
        self::assertSame("<?php // v2\n", $later['src/deep/File.php']['content']);
    }

    public function testAnUnknownRefIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->service()->resolveRef('fixture', 'no-such-branch');
    }

    public function testAnUnknownTagIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->service()->getTag('fixture', 'v9.9.9');
    }
}
