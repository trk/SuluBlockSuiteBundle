<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Totoglu\SuluBlockSuiteBundle\DependencyInjection\SuluBlockSuiteExtension;

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
}
