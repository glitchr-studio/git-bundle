<?php

namespace Tests\Git\Repository;

use Git\Command\SyncCommand;
use Git\DependencyInjection\GitExtension;
use Git\Repository\RepositorySync;
use Git\Warmer\RepositoryWarmer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Cloning and fetching talk to the network: `git:sync` does it, never
 * `cache:warmup` - a warmup builds from what is on disk and must not need the
 * network (it fetched github.com/git/git at each deploy of a site that only
 * declared it).
 */
class RepositorySyncTest extends TestCase
{
    private static function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new GitExtension())->load([['repositories' => ['remote' => ['url' => 'https://example.invalid/remote.git', 'path' => '/nowhere/remote.git']]]], $container);

        return $container;
    }

    public function testTheBundleRegistersNoCacheWarmer(): void
    {
        self::assertSame([], self::container()->findTaggedServiceIds('kernel.cache_warmer'));
    }

    public function testGitSyncIsWhatSyncs(): void
    {
        $container = self::container();

        self::assertSame(RepositorySync::class, $container->getDefinition('git.repository.sync')->getClass());
        self::assertSame('git.repository.sync', (string) $container->getAlias(RepositorySync::class));
        self::assertSame('git.repository.sync', (string) $container->getDefinition('git.command.sync')->getArgument(1));
        self::assertSame(SyncCommand::class, $container->getDefinition('git.command.sync')->getClass());
    }

    public function testTheFormerNameIsADeprecatedSyncAndNoWarmer(): void
    {
        $container = self::container();

        self::assertTrue($container->getDefinition(RepositoryWarmer::class)->isDeprecated());
        self::assertTrue($container->getAlias('git.warmer.repository')->isDeprecated());
        self::assertTrue(is_subclass_of(RepositoryWarmer::class, RepositorySync::class));
        self::assertFalse(is_subclass_of(RepositoryWarmer::class, \Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface::class));
    }

    public function testARepositoryWithoutUrlIsLeftAlone(): void
    {
        $lines = [];
        $synced = (new RepositorySync())->sync('local', ['path' => sys_get_temp_dir()], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertFalse($synced, 'nothing to clone nor fetch');
        self::assertSame([], $lines);
    }

    public function testTheProgressGoesWhereTheCallerSays(): void
    {
        $lines = [];
        $path = sys_get_temp_dir().'/git-bundle-sync-'.bin2hex(random_bytes(4)).'.git';
        // A local "remote" that does not exist: git fails at once, without the network.
        $synced = (new RepositorySync())->sync('missing', ['url' => sys_get_temp_dir().'/no-such-repository-'.bin2hex(random_bytes(4)), 'path' => $path, 'label' => 'Missing'], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertFalse($synced);
        self::assertStringContainsString('Cloning', implode("\n", $lines));
        self::assertStringContainsString('Missing', implode("\n", $lines));
        self::assertDirectoryDoesNotExist($path);
    }
}
