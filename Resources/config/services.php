<?php

use Git\Command\SyncCommand;
use Git\Controller\RepositoryController;
use Git\Repository\RepositoryRegistry;
use Git\Repository\RepositorySync;
use Git\Engine\Git;
use Git\Service\CliService;
use Git\Service\Git2Service;
use Git\Warmer\RepositoryWarmer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire(true)
            ->autoconfigure(true);

    // The configured repositories plus those of every
    // git.repository_provider (Git\Repository\RepositoryProviderInterface).
    $services->set('git.repository.registry', RepositoryRegistry::class)
        ->args(['%git.repositories%', tagged_iterator('git.repository_provider')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->alias(RepositoryRegistry::class, 'git.repository.registry');

    // The engine: the git binary through symfony/process (Git\Engine\*), framework-free.
    $services->set('git.engine', Git::class)
        ->args(['%git.engine.binary%', '%git.engine.timeout%']);

    $services->alias(Git::class, 'git.engine');

    // The viewer's two backends; git.viewer (and RepositoryViewerInterface)
    // is the one `git.backend` chooses (GitExtension).
    $services->set('git.service.cli', CliService::class)
        ->args([new Reference('git.repository.registry'), new Reference('git.engine')])
        ->public();

    $services->alias(CliService::class, 'git.service.cli');

    $services->set('git.service.git2', Git2Service::class)
        ->args([new Reference('git.repository.registry')])
        ->public();

    $services->alias(Git2Service::class, 'git.service.git2');

    // Clones and fetches the repositories that have a url: git:sync runs it,
    // never cache:warmup (a warmup must not need the network).
    $services->set('git.repository.sync', RepositorySync::class);

    $services->alias(RepositorySync::class, 'git.repository.sync');

    // The former name, no cache warmer any more.
    $services->set(RepositoryWarmer::class)
        ->deprecate('git/git-bundle', '1.x', 'The "%service_id%" service is deprecated, use "Git\\Repository\\RepositorySync" (git:sync): repositories are no longer synced during cache:warmup.');

    $services->alias('git.warmer.repository', RepositoryWarmer::class)
        ->deprecate('git/git-bundle', '1.x', 'The "%alias_id%" service alias is deprecated, use "git.repository.sync".');

    $services->set('git.command.sync', SyncCommand::class)
        ->args([new Reference('git.repository.registry'), new Reference('git.repository.sync')])
        ->tag('console.command');

    // Registered under the FQCN: attribute-imported routes reference the
    // controller by class name. Keep the short id as a BC alias.
    $services->set(RepositoryController::class)
        ->args([
            new Reference('git.viewer'),
            '%git.access_role%',
            '%git.repository_attribute%',
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->alias('git.controller.repository', RepositoryController::class)
        ->public();
};
