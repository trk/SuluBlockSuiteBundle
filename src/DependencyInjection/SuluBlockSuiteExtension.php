<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SuluBlockSuiteExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('sulu_admin')) {
            $container->prependExtensionConfig('sulu_admin', [
                'templates' => [
                    'block' => [
                        'directories' => [
                            'sulu_block_suite' => \dirname(__DIR__, 2) . '/config/templates/blocks',
                        ],
                    ],
                    'snippet' => [
                        'directories' => [
                            'sulu_block_suite' => \dirname(__DIR__, 2) . '/config/templates/snippets',
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'paths' => [
                    \dirname(__DIR__, 2) . '/templates' => 'SuluBlockSuite',
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');

        /** @var array{enabled: bool, yaml_dir: string|list<string>, enable_snippet_presets: bool} $presetsConfig */
        $presetsConfig = $config['presets'];
        $container->setParameter('sulu_block_suite.presets.enabled', $presetsConfig['enabled']);
        $container->setParameter('sulu_block_suite.presets.yaml_dir', $presetsConfig['yaml_dir']);
        $container->setParameter('sulu_block_suite.presets.enable_snippet_presets', $presetsConfig['enable_snippet_presets']);

        /** @var array{enabled: bool} $reusableConfig */
        $reusableConfig = $config['reusable_blocks'];
        $container->setParameter('sulu_block_suite.reusable_blocks.enabled', $reusableConfig['enabled']);

        /** @var array{enabled: bool, yaml_dir: string|list<string>, mappings: array<string, array<string, string>>} $layoutConfig */
        $layoutConfig = $config['layout'];
        $container->setParameter('sulu_block_suite.layout.enabled', $layoutConfig['enabled']);
        $container->setParameter('sulu_block_suite.layout.yaml_dir', $layoutConfig['yaml_dir']);
        $container->setParameter('sulu_block_suite.layout.mappings', $layoutConfig['mappings']);

        /** @var array{schema_dir: string|list<string>} $propsConfig */
        $propsConfig = $config['props'];
        $container->setParameter('sulu_block_suite.props.schema_dir', $propsConfig['schema_dir']);
    }
}
