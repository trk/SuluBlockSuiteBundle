<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sulu_block_suite');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('presets')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->variableNode('yaml_dir')
                            ->defaultValue('%kernel.project_dir%/config/blocks/presets')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !\is_string($v) && !\is_array($v))
                                ->thenInvalid('yaml_dir must be a string or array of strings.')
                            ->end()
                        ->end()
                        ->booleanNode('enable_snippet_presets')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('props')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('schema_dir')
                            ->defaultValue('%kernel.project_dir%/config/blocks/props')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !\is_string($v) && !\is_array($v))
                                ->thenInvalid('schema_dir must be a string or array of strings.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('reusable_blocks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('layout')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->variableNode('yaml_dir')
                            ->defaultValue('%kernel.project_dir%/config/blocks/layouts')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !\is_string($v) && !\is_array($v))
                                ->thenInvalid('yaml_dir must be a string or array of strings.')
                            ->end()
                        ->end()
                        ->arrayNode('mappings')
                            ->useAttributeAsKey('group')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->useAttributeAsKey('name')
                                ->normalizeKeys(false)
                                ->prototype('scalar')->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rendering')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('template_namespaces')
                            ->defaultValue(['blocks', '@SuluBlockSuite/blocks'])
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !\is_string($v) && !\is_array($v))
                                ->thenInvalid('template_namespaces must be a string or array of strings.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
