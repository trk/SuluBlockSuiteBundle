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
                            ->defaultValue([
                                'container_width' => [
                                    'full' => 'w-full',
                                    'boxed' => 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8',
                                    'narrow' => 'max-w-4xl mx-auto px-4 sm:px-6',
                                ],
                                'content_padding' => [
                                    'none' => 'py-0',
                                    'compact' => 'py-6 md:py-8',
                                    'normal' => 'py-12 md:py-16',
                                    'spacious' => 'py-16 md:py-24',
                                ],
                                'margin_top' => [
                                    'none' => 'mt-0',
                                    'xs' => 'mt-2',
                                    'sm' => 'mt-4',
                                    'md' => 'mt-6',
                                    'lg' => 'mt-10',
                                    'xl' => 'mt-16',
                                    'auto' => 'mt-auto',
                                ],
                                'margin_bottom' => [
                                    'none' => 'mb-0',
                                    'xs' => 'mb-2',
                                    'sm' => 'mb-4',
                                    'md' => 'mb-6',
                                    'lg' => 'mb-10',
                                    'xl' => 'mb-16',
                                    'auto' => 'mb-auto',
                                ],
                                'max_width' => [
                                    'small' => 'max-w-xl mx-auto',
                                    'medium' => 'max-w-3xl mx-auto',
                                    'large' => 'max-w-5xl mx-auto',
                                    'xlarge' => 'max-w-7xl mx-auto',
                                    '2xlarge' => 'max-w-(--breakpoint-2xl) mx-auto',
                                ],
                                'block_align' => [
                                    'left' => 'text-left',
                                    'center' => 'text-center mx-auto',
                                    'right' => 'text-right ml-auto',
                                ],
                            ])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
