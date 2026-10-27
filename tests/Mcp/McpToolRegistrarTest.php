<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Totoglu\SuluBlockSuiteBundle\Mcp\McpToolRegistrar;

final class McpToolRegistrarTest extends TestCase
{
    public function testRegistersMcpToolAndResourceTagsWhenMcpIsAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.mcp_tools', new Definition(\stdClass::class));
        $container->setDefinition('app.mcp_resource', new Definition(\stdClass::class));

        McpToolRegistrar::registerOptionalTags(
            $container,
            'app.mcp_tools',
            [
                [
                    'name' => 'demo_tool',
                    'method' => 'runDemo',
                    'description' => 'Run demo tool',
                ],
            ],
            'app.mcp_resource',
            [
                'uri' => 'sulu://demo/ref',
                'name' => 'demo_ref',
                'mimeType' => 'text/markdown',
                'description' => 'Demo reference',
            ],
        );

        $toolsTags = $container->getDefinition('app.mcp_tools')->getTag('mcp.tool');
        self::assertNotEmpty($toolsTags);
        self::assertIsArray($toolsTags[0]);
        self::assertSame('demo_tool', $toolsTags[0]['name'] ?? null);

        $resTags = $container->getDefinition('app.mcp_resource')->getTag('mcp.resource');
        self::assertNotEmpty($resTags);
        self::assertIsArray($resTags[0]);
        self::assertSame('sulu://demo/ref', $resTags[0]['uri'] ?? null);
    }
}
