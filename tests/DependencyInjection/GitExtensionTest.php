<?php

namespace Tests\Git\DependencyInjection;

use Git\DependencyInjection\GitExtension;
use Git\Engine\Git;
use Git\Service\RepositoryViewerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** `git.backend` chooses the viewer's backend: the git binary by default, libgit2 on demand; the engine takes `git.engine`. */
final class GitExtensionTest extends TestCase
{
    public function testTheGitBinaryIsTheDefaultBackend(): void
    {
        $container = $this->load([]);
        self::assertSame('git.service.cli', (string) $container->getAlias('git.viewer'));
        self::assertSame('git.service.cli', (string) $container->getAlias(RepositoryViewerInterface::class));
        self::assertSame('git', $container->getParameter('git.engine.binary'));
        self::assertSame(Git::class, $container->getDefinition('git.engine')->getClass());
    }

    public function testLibgit2IsChosenByConfiguration(): void
    {
        $container = $this->load(['backend' => 'libgit2', 'engine' => ['binary' => '/usr/bin/git', 'timeout' => 10]]);
        self::assertSame('git.service.git2', (string) $container->getAlias('git.viewer'));
        self::assertSame('/usr/bin/git', $container->getParameter('git.engine.binary'));
        self::assertSame(10.0, $container->getParameter('git.engine.timeout'));
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new GitExtension())->load([$config], $container);

        return $container;
    }
}
