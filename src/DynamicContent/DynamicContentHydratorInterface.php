<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

interface DynamicContentHydratorInterface
{
    public const SOURCE_NONE = 'none';
    public const SOURCE_CURRENT_PAGE = 'current_page';
    public const SOURCE_SPECIFIC_PAGE = 'specific_page';
    public const SOURCE_PAGE_CHILDREN = 'page_children';
    public const SOURCE_CUSTOM_QUERY = 'custom_query';

    /**
     * Hydrates a single block with dynamic content if configured.
     *
     * @param array<string, mixed> $block
     * @param string|object|array<string, mixed>|null $contextPage Current page document, array data, or view context
     *
     * @return list<array<string, mixed>> List of hydrated blocks (0 if filtered out, 1 for single, N for repeater)
     */
    public function hydrateBlock(array $block, string|object|array|null $contextPage = null): array;

    /**
     * Hydrates an array of blocks, flattening any multi-item repeaters.
     *
     * @param iterable<array<string, mixed>> $blocks
     * @param string|object|array<string, mixed>|null $contextPage
     *
     * @return list<array<string, mixed>>
     */
    public function hydrateBlocks(iterable $blocks, string|object|array|null $contextPage = null): array;
}
