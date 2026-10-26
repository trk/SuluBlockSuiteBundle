<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DependencyInjection;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuitePayloadComposer;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuiteSchemaInspector;
use Totoglu\SuluBlockSuiteBundle\DependencyInjection\SuluBlockSuiteExtension;
use Totoglu\SuluBlockSuiteBundle\Mcp\BlockSuiteMcpTools;

final class SuluBlockSuiteExtensionTest extends TestCase
{
    public function testPrependRegistersOnlyFormsAndBlockTemplateDirectories(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'sulu_admin';
            }
        });

        $extension = new SuluBlockSuiteExtension();
        $extension->prepend($container);

        $suluAdminConfigs = $container->getExtensionConfig('sulu_admin');
        self::assertCount(1, $suluAdminConfigs);

        /** @var array{forms?: array{directories?: list<string>}, templates?: array<string, mixed>} $config */
        $config = $suluAdminConfigs[0];
        self::assertArrayHasKey('templates', $config);
        self::assertArrayHasKey('block', $config['templates']);
        self::assertArrayNotHasKey('snippet', $config['templates']);
    }

    public function testLoadMergesAndSortsTemplateNamespacesByPriorityDescending(): void
    {
        $container = new ContainerBuilder();
        $extension = new SuluBlockSuiteExtension();

        $extension->load([
            [
                'rendering' => [
                    'template_namespaces' => [
                        '@TailwindBaseBlocks/blocks' => 100,
                    ],
                ],
            ],
            [
                'rendering' => [
                    'template_namespaces' => [
                        '@ThemeChildBlocks/blocks' => 200,
                    ],
                ],
            ],
        ], $container);

        /** @var array<string, int> $resolvedNamespaces */
        $resolvedNamespaces = $container->getParameter('sulu_block_suite.rendering.template_namespaces');

        self::assertSame(
            [
                'blocks' => 1000,
                '@ThemeChildBlocks/blocks' => 200,
                '@TailwindBaseBlocks/blocks' => 100,
                '@SuluBlockSuite/blocks' => 0,
            ],
            $resolvedNamespaces,
        );
    }

    public function testLoadRegistersAiSchemaInspectorAndPayloadComposerServices(): void
    {
        $container = new ContainerBuilder();
        $extension = new SuluBlockSuiteExtension();

        $extension->load([], $container);

        self::assertTrue($container->hasDefinition(BlockSuiteSchemaInspector::class));
        self::assertTrue($container->hasDefinition(BlockSuitePayloadComposer::class));
        self::assertTrue($container->hasDefinition(BlockSuiteMcpTools::class));

        /** @var list<array{method?: string}> $tags */
        $tags = $container->getDefinition(BlockSuiteMcpTools::class)->getTag('mcp.tool');

        self::assertSame(
            [
                ['method' => 'inspectBlocks'],
                ['method' => 'listPresets'],
                ['method' => 'composeBlock'],
            ],
            $tags,
        );

        $reflection = new \ReflectionClass(BlockSuiteMcpTools::class);
        foreach ($tags as $tag) {
            $methodName = $tag['method'];
            self::assertTrue(
                $reflection->hasMethod($methodName),
                \sprintf('Tagged mcp.tool method "%s" must exist on %s.', $methodName, BlockSuiteMcpTools::class),
            );

            $method = $reflection->getMethod($methodName);
            $attributes = $method->getAttributes(McpTool::class);
            self::assertNotEmpty(
                $attributes,
                \sprintf('Tagged mcp.tool method "%s::%s" must carry #[McpTool].', BlockSuiteMcpTools::class, $methodName),
            );

            /** @var McpTool $toolAttribute */
            $toolAttribute = $attributes[0]->newInstance();
            self::assertNotEmpty($toolAttribute->name);
            self::assertNotEmpty($toolAttribute->description);
        }
    }

    public function testMultiWebspaceAutoDetectsFromKernelBundlesAndAllowsExplicitOverride(): void
    {
        $extension = new SuluBlockSuiteExtension();

        // 1. Without SuluMultiWebspaceBundle in kernel.bundles -> disabled
        $containerWithoutBundle = new ContainerBuilder();
        $containerWithoutBundle->setParameter('kernel.bundles', [
            'SuluBlockSuiteBundle' => 'Totoglu\\SuluBlockSuiteBundle\\SuluBlockSuiteBundle',
        ]);
        $extension->load([], $containerWithoutBundle);
        self::assertFalse($containerWithoutBundle->getParameter('sulu_block_suite.multi_webspace.enabled'));

        // 2. With SuluMultiWebspaceBundle in kernel.bundles -> enabled
        $containerWithBundle = new ContainerBuilder();
        $containerWithBundle->setParameter('kernel.bundles', [
            'SuluBlockSuiteBundle' => 'Totoglu\\SuluBlockSuiteBundle\\SuluBlockSuiteBundle',
            'SuluMultiWebspaceBundle' => 'Totoglu\\SuluMultiWebspaceBundle\\SuluMultiWebspaceBundle',
        ]);
        $extension->load([], $containerWithBundle);
        self::assertTrue($containerWithBundle->getParameter('sulu_block_suite.multi_webspace.enabled'));

        // 3. Explicit config override
        $containerExplicitOverride = new ContainerBuilder();
        $containerExplicitOverride->setParameter('kernel.bundles', [
            'SuluBlockSuiteBundle' => 'Totoglu\\SuluBlockSuiteBundle\\SuluBlockSuiteBundle',
            'SuluMultiWebspaceBundle' => 'Totoglu\\SuluMultiWebspaceBundle\\SuluMultiWebspaceBundle',
        ]);
        $extension->load([
            [
                'multi_webspace' => [
                    'enabled' => false,
                ],
            ],
        ], $containerExplicitOverride);
        self::assertFalse($containerExplicitOverride->getParameter('sulu_block_suite.multi_webspace.enabled'));
    }
}
