<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Mcp;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Shared Dependency Injection helper that conditionally tags MCP tool and resource services
 * (`mcp.tool`, `mcp.resource`, `ai.tool`) when `sulu/mcp-bundle`, `symfony/mcp-bundle`,
 * or `symfony/ai-agent` is installed.
 */
final class McpToolRegistrar
{
    /**
     * @param list<array{name: string, method: string, description: string, title?: string}> $tools
     * @param array{uri: string, name: string, mimeType: string, description: string}|null   $resourceMeta
     */
    public static function registerOptionalTags(
        ContainerBuilder $container,
        string $toolsServiceId,
        array $tools,
        ?string $resourceServiceId = null,
        ?array $resourceMeta = null,
        string $resourceMethod = 'getArchitectureReference',
    ): void {
        $hasMcp = $container->hasExtension('mcp')
            || $container->hasExtension('sulu_mcp')
            || \class_exists('Mcp\\Capability\\Attribute\\McpTool')
            || \class_exists('Sulu\\Mcp\\Infrastructure\\Symfony\\HttpKernel\\SuluMcpBundle');

        if ($hasMcp && $container->hasDefinition($toolsServiceId)) {
            $toolsDef = $container->getDefinition($toolsServiceId);
            foreach ($tools as $tool) {
                $toolsDef->addTag('mcp.tool', [
                    'name' => $tool['name'],
                    'method' => $tool['method'],
                    'description' => $tool['description'],
                ]);
            }
        }

        if ($hasMcp && null !== $resourceServiceId && null !== $resourceMeta && $container->hasDefinition($resourceServiceId)) {
            $resourceDef = $container->getDefinition($resourceServiceId);
            $resourceDef->addTag('mcp.resource', [
                'uri' => $resourceMeta['uri'],
                'name' => $resourceMeta['name'],
                'mimeType' => $resourceMeta['mimeType'],
                'description' => $resourceMeta['description'],
                'method' => $resourceMethod,
            ]);
        }

        if (\class_exists('Symfony\\AI\\Agent\\Toolbox\\Attribute\\AsTool') && $container->hasDefinition($toolsServiceId)) {
            $toolsDef = $container->getDefinition($toolsServiceId);
            foreach ($tools as $tool) {
                $toolsDef->addTag('ai.tool', [
                    'name' => $tool['name'],
                    'method' => $tool['method'],
                    'description' => $tool['description'],
                ]);
            }
        }
    }
}
