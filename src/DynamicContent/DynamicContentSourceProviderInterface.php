<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

/**
 * Contract for registering custom dynamic content sources (e.g. Articles, Products, Events)
 * into the Sulu Block Suite dynamic content engine.
 */
interface DynamicContentSourceProviderInterface
{
    /**
     * Unique source type identifier (e.g. "articles", "products").
     */
    public function getKey(): string;

    /**
     * Human-readable label for the Sulu Admin UI.
     */
    public function getLabel(string $locale = 'en'): string;

    /**
     * Whether this source returns a collection of items (true) or a single item (false).
     */
    public function isMultiItem(): bool;

    /**
     * Returns available source fields for field mapping in the Sulu Admin UI.
     *
     * @return list<array{name: string, label: string, type: string}>
     */
    public function getSourceFields(string $locale = 'en'): array;

    /**
     * Resolves a single item for single-item source providers.
     *
     * @param array<string, mixed> $config
     * @param string|object|array<mixed, mixed>|null $contextPage
     *
     * @return array<string, mixed>|null
     */
    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array;

    /**
     * Resolves a collection of items for multi-item source providers.
     *
     * @param array<string, mixed> $config
     * @param string|object|array<mixed, mixed>|null $contextPage
     *
     * @return list<array<string, mixed>>
     */
    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array;
}
