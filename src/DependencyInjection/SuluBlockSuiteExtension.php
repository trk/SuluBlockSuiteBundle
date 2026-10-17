<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\NestedBlockFlattenerInterface;

final class SuluBlockSuiteExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('sulu_admin')) {
            $container->prependExtensionConfig('sulu_admin', [
                'forms' => [
                    'directories' => [
                        \dirname(__DIR__, 2) . '/config/forms',
                    ],
                ],
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
        $container->registerForAutoconfiguration(BlockTransformerInterface::class)
            ->addTag('sulu_block_suite.block_transformer');

        $container->registerForAutoconfiguration(NestedBlockFlattenerInterface::class)
            ->addTag('sulu_block_suite.nested_block_flattener');

        $container->registerForAutoconfiguration(DynamicContentSourceProviderInterface::class)
            ->addTag('sulu_block_suite.dynamic_source_provider');

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

        /** @var array{template_namespaces: string|array<array-key, string>} $renderingConfig */
        $renderingConfig = $config['rendering'];
        $namespaces = \is_array($renderingConfig['template_namespaces'])
            ? \array_values($renderingConfig['template_namespaces'])
            : [$renderingConfig['template_namespaces']];
        $container->setParameter('sulu_block_suite.rendering.template_namespaces', $namespaces);

        /** @var array{srcset_formats: array<string, int>} $mediaConfig */
        $mediaConfig = $config['media'];
        $container->setParameter('sulu_block_suite.media.srcset_formats', $mediaConfig['srcset_formats']);
        SuluDataNormalizer::setDefaultSrcSetFormats($mediaConfig['srcset_formats']);
    }
}
