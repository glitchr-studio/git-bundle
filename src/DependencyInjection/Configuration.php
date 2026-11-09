<?php

namespace Git\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('git');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->enumNode('backend')
                    ->info('What reads the repositories for the viewer: the git binary (cli, no extension needed) or libgit2 through the php-git2 extension')
                    ->values(['cli', 'libgit2'])
                    ->defaultValue('cli')
                ->end()
                ->arrayNode('engine')
                    ->info('The git binary (Git\\Engine\\Git): the cli backend, and every write (Git\\Engine\\GitRepository)')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('binary')->defaultValue('git')->end()
                        ->floatNode('timeout')->info('Seconds a command may take')->defaultValue(30.0)->end()
                    ->end()
                ->end()
                ->scalarNode('route_prefix')
                    ->info('URL prefix for all git routes')
                    ->defaultValue('/git')
                ->end()
                ->scalarNode('access_role')
                    ->info('Role required to access the git viewer')
                    ->defaultValue('ROLE_ADMIN')
                ->end()
                ->scalarNode('repository_attribute')
                    ->info('When set, each repository is also checked with isGranted(<attribute>, <name>) - for a voter granting repositories one by one')
                    ->defaultNull()
                ->end()
                ->arrayNode('repositories')
                    ->info('Named repositories to expose')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')
                                ->info('Absolute path to the git repository (created automatically if url is set)')
                                ->isRequired()
                            ->end()
                            ->scalarNode('url')
                                ->info('Remote URL: git:sync clones it into path when path does not exist, and fetches it otherwise')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('label')
                                ->info('Human-readable name shown in the UI')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('description')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('default_branch')
                                ->defaultValue('HEAD')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
