<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuitePayloadComposer;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuiteSchemaInspector;

/**
 * Exposes Sulu Block Suite schemas, visual presets, and canonical block payload composition
 * to MCP clients (`sulu/mcp-bundle`, `symfony/mcp-bundle`, and `symfony/ai-mate`).
 */
final class BlockSuiteMcpTools
{
    public function __construct(
        private readonly BlockSuiteSchemaInspector $schemaInspector,
        private readonly BlockSuitePayloadComposer $payloadComposer,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     locale?: string,
     *     count?: int,
     *     blocks?: list<array<string, mixed>>,
     *     block?: array<string, mixed>,
     *     error?: string
     * }
     */
    #[McpTool(
        name: 'sulu_block_suite_blocks',
        description: 'Inspect Sulu Block Suite block schemas with progressive disclosure. Omit blockType to list all block types with preset counts; provide blockType to inspect content fields, inline field option groups (props/*), settings props, and presets without dynamic/advanced prop noise.',
    )]
    public function inspectBlocks(
        #[Schema(description: 'Block type key to inspect in detail, or null to list all block types')]
        ?string $blockType = null,
        #[Schema(description: 'Locale code (e.g. "en", "de", "tr")')]
        string $locale = 'en',
        #[Schema(description: 'Set true only when editing HTML id, CSS classes, custom css, attributes, or status props')]
        bool $includeAdvanced = false,
        #[Schema(description: 'Set true only when configuring dynamic content data sources (pages, articles, categories, media)')]
        bool $includeDynamic = false,
    ): array {
        $cleanType = null !== $blockType ? \trim($blockType) : '';
        if ('' === $cleanType) {
            $blocks = $this->schemaInspector->listBlocks($locale);

            return [
                'ok' => true,
                'locale' => $locale,
                'count' => \count($blocks),
                'blocks' => $blocks,
            ];
        }

        $schema = $this->schemaInspector->inspectBlock(
            $cleanType,
            $locale,
            $includeAdvanced,
            $includeDynamic,
        );

        if (null === $schema) {
            $availableTypes = \array_map(
                static fn (array $b): string => $b['type'],
                $this->schemaInspector->listBlocks($locale),
            );

            return [
                'ok' => false,
                'error' => \sprintf(
                    'Unknown block type "%s". Available block types: %s.',
                    $cleanType,
                    [] !== $availableTypes ? \implode(', ', $availableTypes) : 'none',
                ),
            ];
        }

        return [
            'ok' => true,
            'locale' => $locale,
            'block' => $schema,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     block_type: string,
     *     locale: string,
     *     count: int,
     *     presets: list<array{id: string, title: string, description: ?string, props: array<string, mixed>, values: array<string, mixed>}>
     * }
     */
    #[McpTool(
        name: 'sulu_block_suite_presets',
        description: 'List visual presets (YAML and Sulu Snippet presets) for a Sulu Block Suite block type, including default props and content values.',
    )]
    public function listPresets(
        #[Schema(description: 'Block type key (e.g. "hero", "feature_grid")')]
        string $blockType,
        #[Schema(description: 'Locale code (e.g. "en", "de", "tr")')]
        string $locale = 'en',
        #[Schema(description: 'Optional case-insensitive filter matching preset id, title, or description')]
        ?string $query = null,
    ): array {
        $presets = $this->schemaInspector->listPresets($blockType, $locale, $query);

        return [
            'ok' => true,
            'block_type' => \trim($blockType),
            'locale' => $locale,
            'count' => \count($presets),
            'presets' => $presets,
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $props
     *
     * @return array{ok: bool, block?: array<string, mixed>, error?: string}
     */
    #[McpTool(
        name: 'sulu_block_suite_compose_block',
        description: 'Compose and validate a Sulu Block Suite block payload by merging an optional visual preset with content fields and props (synchronizing nested "props" and flat "props/*" keys). Pass the returned "block" object directly to sulu_block_add, sulu_block_update, or sulu_page_create.',
    )]
    public function composeBlock(
        #[Schema(description: 'Block type key')]
        string $blockType,
        #[Schema(description: 'Content tab field values (e.g. {"title": "Welcome", "description": "<p>...</p>"})')]
        array $content = [],
        #[Schema(description: 'Block Suite props and inline field option values, with or without "props/" prefix (e.g. {"title_size": "xl", "background": "dark"})')]
        array $props = [],
        #[Schema(description: 'Optional preset ID from sulu_block_suite_presets to apply as base defaults')]
        ?string $preset = null,
        #[Schema(description: 'Locale code (e.g. "en", "de", "tr")')]
        string $locale = 'en',
    ): array {
        try {
            $block = $this->payloadComposer->compose(
                blockType: $blockType,
                content: $content,
                props: $props,
                preset: $preset,
                locale: $locale,
            );

            return [
                'ok' => true,
                'block' => $block,
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
