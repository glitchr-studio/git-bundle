<?php

namespace Git\DependencyInjection;

use Git\Repository\RepositoryProviderInterface;
use Git\Service\RepositoryViewerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class GitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/Resources/config'));
        $loader->load('services.php');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('git.route_prefix', $config['route_prefix']);
        $container->setParameter('git.access_role', $config['access_role']);
        $container->setParameter('git.repository_attribute', $config['repository_attribute']);

        $container->registerForAutoconfiguration(RepositoryProviderInterface::class)
            ->addTag('git.repository_provider');
        $container->setParameter('git.repositories', $config['repositories']);
        $container->setParameter('git.engine.binary', $config['engine']['binary']);
        $container->setParameter('git.engine.timeout', (float) $config['engine']['timeout']);

        // The viewer reads through one interface; the backend says which.
        $viewer = 'libgit2' === $config['backend'] ? 'git.service.git2' : 'git.service.cli';
        $container->setAlias('git.viewer', $viewer)->setPublic(true);
        $container->setAlias(RepositoryViewerInterface::class, $viewer)->setPublic(true);
    }
}
