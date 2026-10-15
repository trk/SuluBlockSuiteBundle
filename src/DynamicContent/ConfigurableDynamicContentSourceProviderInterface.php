<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

/**
 * Extended contract for dynamic content source providers that declare their own
 * Sulu Admin UI configuration fields and/or expose multiple source descriptors
 * (such as both a single-item selection mode and a collection query mode).
 *
 * @phpstan-type ConfigFieldOption array{label: string, value: string}
 * @phpstan-type ConfigFieldSchema array{
 *     name: string,
 *     label: string,
 *     type: string,
 *     description?: string,
 *     required?: bool,
 *     default?: mixed,
 *     col_span?: int,
 *     min?: int,
 *     max?: int,
 *     placeholder?: string,
 *     icon_set?: string,
 *     options?: list<ConfigFieldOption>,
 *     trigger_source_fields_reload?: bool,
 * }
 * @phpstan-type SourceDescriptor array{
 *     key: string,
 *     label: string,
 *     multi_item: bool,
 *     config_fields: list<ConfigFieldSchema>,
 * }
 */
interface ConfigurableDynamicContentSourceProviderInterface extends DynamicContentSourceProviderInterface
{
    /**
     * Returns the admin configuration form fields schema for the primary source mode.
     *
     * @return list<ConfigFieldSchema>
     */
    public function getConfigFields(string $locale = 'en'): array;

    /**
     * Optional source key for a paired single-item selection mode when the primary provider
     * is a collection provider (e.g. "single_product" paired with "products").
     */
    public function getSingleKey(): ?string;

    /**
     * Human-readable label for the paired single-item selection mode.
     */
    public function getSingleLabel(string $locale = 'en'): string;

    /**
     * Admin configuration form fields schema for the paired single-item selection mode
     * (e.g. a "single_product_selection" or "single_article_selection" field).
     *
     * @return list<ConfigFieldSchema>
     */
    public function getSingleConfigFields(string $locale = 'en'): array;

    /**
     * Returns all source descriptors exposed by this provider (single, collection, or multiple variants).
     *
     * @return list<SourceDescriptor>
     */
    public function getSourceDescriptors(string $locale = 'en'): array;

    /**
     * Resolves available source fields for field mapping, optionally enriched using request context
     * (such as a selected item ID or template).
     *
     * @param array<string, mixed> $context
     *
     * @return list<array{name: string, label: string, type: string}>
     */
    public function resolveSourceFields(string $locale = 'en', array $context = []): array;
}
