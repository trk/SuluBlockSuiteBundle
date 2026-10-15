<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base class for Dynamic Content source providers.
 *
 * Allows any bundle (e.g. ProductBundle, EventBundle, SuluArticleBundle) to register
 * single-item and/or collection resolvers along with their Sulu native selection fields
 * and query filter schemas without modifying the Block Suite core.
 *
 * @phpstan-import-type ConfigFieldOption from ConfigurableDynamicContentSourceProviderInterface
 * @phpstan-import-type ConfigFieldSchema from ConfigurableDynamicContentSourceProviderInterface
 * @phpstan-import-type SourceDescriptor from ConfigurableDynamicContentSourceProviderInterface
 */
abstract class AbstractDynamicContentSourceProvider implements ConfigurableDynamicContentSourceProviderInterface
{
    public function isMultiItem(): bool
    {
        return true;
    }

    public function getSingleKey(): ?string
    {
        return null;
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return $this->getLabel($locale);
    }

    public function getSingleConfigFields(string $locale = 'en'): array
    {
        return [];
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        return $this->isMultiItem()
            ? self::defaultCollectionConfigFields()
            : [];
    }

    public function getSourceDescriptors(string $locale = 'en'): array
    {
        $descriptors = [];

        $singleKey = $this->getSingleKey();
        if (null !== $singleKey && '' !== \trim($singleKey)) {
            $descriptors[] = [
                'key' => \trim($singleKey),
                'label' => $this->getSingleLabel($locale),
                'multi_item' => false,
                'config_fields' => $this->getSingleConfigFields($locale),
            ];
        }

        $descriptors[] = [
            'key' => $this->getKey(),
            'label' => $this->getLabel($locale),
            'multi_item' => $this->isMultiItem(),
            'config_fields' => $this->getConfigFields($locale),
        ];

        return $descriptors;
    }

    public function resolveSourceFields(string $locale = 'en', array $context = []): array
    {
        return $this->getSourceFields($locale);
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        return null;
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        return [];
    }

    /**
     * Builds a Sulu native selection field definition (e.g. single_page_selection, page_selection,
     * single_article_selection, article_selection, single_product_selection, product_selection, etc.).
     *
     * @return ConfigFieldSchema
     */
    protected function createSelectionField(
        string $name,
        string $type,
        string $label,
        ?string $description = null,
        bool $required = false,
        int $colSpan = 12,
        bool $triggerSourceFieldsReload = false,
    ): array {
        $field = [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'col_span' => $colSpan,
        ];

        if (null !== $description && '' !== $description) {
            $field['description'] = $description;
        }

        if ($triggerSourceFieldsReload) {
            $field['trigger_source_fields_reload'] = true;
        }

        return $field;
    }

    /**
     * Standard pagination & sorting configuration fields for collection providers.
     *
     * @param list<ConfigFieldOption>|null $sortOptions
     *
     * @return list<ConfigFieldSchema>
     */
    public static function defaultCollectionConfigFields(
        int $defaultLimit = 10,
        string $defaultSortBy = 'created',
        ?array $sortOptions = null,
    ): array {
        $resolvedSortOptions = $sortOptions ?? [
            ['value' => 'created', 'label' => 'sulu_block_suite.dynamic_sort_created'],
            ['value' => 'changed', 'label' => 'sulu_block_suite.dynamic_sort_changed'],
            ['value' => 'authored', 'label' => 'sulu_block_suite.dynamic_sort_authored'],
            ['value' => 'title', 'label' => 'sulu_block_suite.dynamic_sort_title'],
        ];

        return [
            [
                'name' => 'limit',
                'label' => 'sulu_block_suite.dynamic_limit',
                'description' => 'sulu_block_suite.dynamic_limit_desc',
                'type' => 'number',
                'default' => $defaultLimit,
                'min' => 1,
                'max' => 100,
                'col_span' => 6,
            ],
            [
                'name' => 'offset',
                'label' => 'sulu_block_suite.dynamic_offset',
                'description' => 'sulu_block_suite.dynamic_offset_desc',
                'type' => 'number',
                'default' => 0,
                'min' => 0,
                'max' => 100,
                'col_span' => 6,
            ],
            [
                'name' => 'sort_by',
                'label' => 'sulu_block_suite.dynamic_sort_by',
                'type' => 'select',
                'required' => true,
                'default' => $defaultSortBy,
                'col_span' => 6,
                'options' => $resolvedSortOptions,
            ],
            [
                'name' => 'sort_order',
                'label' => 'sulu_block_suite.dynamic_sort_order',
                'type' => 'select',
                'required' => true,
                'default' => 'desc',
                'col_span' => 6,
                'options' => [
                    ['value' => 'desc', 'label' => 'sulu_block_suite.dynamic_sort_desc'],
                    ['value' => 'asc', 'label' => 'sulu_block_suite.dynamic_sort_asc'],
                ],
            ],
        ];
    }

    /**
     * @param list<ConfigFieldOption>|null $sortOptions
     *
     * @return list<ConfigFieldSchema>
     */
    protected function createPaginationAndSortFields(
        int $defaultLimit = 10,
        string $defaultSortBy = 'created',
        ?array $sortOptions = null,
    ): array {
        return self::defaultCollectionConfigFields($defaultLimit, $defaultSortBy, $sortOptions);
    }

    /**
     * Standard Sulu taxonomy & template filter fields (Template key, Tag Selection, Category Selection).
     *
     * @return list<ConfigFieldSchema>
     */
    protected function createTaxonomyFilterFields(
        bool $includeTemplateFilter = false,
        bool $includeTags = true,
        bool $includeCategories = true,
    ): array {
        $fields = [];
        $count = ($includeTemplateFilter ? 1 : 0) + ($includeTags ? 1 : 0) + ($includeCategories ? 1 : 0);
        $colSpan = $count > 0 ? (int) \floor(12 / $count) : 6;

        if ($includeTemplateFilter) {
            $fields[] = [
                'name' => 'template',
                'label' => 'sulu_block_suite.dynamic_filter_template',
                'description' => 'sulu_block_suite.dynamic_filter_template_desc',
                'type' => 'text',
                'placeholder' => 'e.g. default',
                'col_span' => $colSpan,
            ];
        }

        if ($includeTags) {
            $fields[] = [
                'name' => 'tag_ids',
                'label' => 'sulu_block_suite.dynamic_filter_tags',
                'description' => 'sulu_block_suite.dynamic_filter_tags_desc',
                'type' => 'tag_selection',
                'col_span' => $colSpan,
            ];
        }

        if ($includeCategories) {
            $fields[] = [
                'name' => 'category_ids',
                'label' => 'sulu_block_suite.dynamic_filter_categories',
                'description' => 'sulu_block_suite.dynamic_filter_categories_desc',
                'type' => 'category_selection',
                'col_span' => $colSpan,
            ];
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    protected function normalizeStringList(mixed $raw): array
    {
        if (\is_string($raw)) {
            return \array_values(\array_filter(
                \array_map('trim', \explode(',', $raw)),
                static fn (string $v): bool => '' !== $v,
            ));
        }

        if (\is_array($raw)) {
            $list = [];
            foreach ($raw as $item) {
                if (\is_string($item) && '' !== \trim($item)) {
                    $list[] = \trim($item);
                } elseif (\is_int($item) || \is_float($item)) {
                    $list[] = (string) $item;
                }
            }

            return $list;
        }

        return [];
    }

    /**
     * @return list<int>
     */
    protected function normalizeIntList(mixed $raw): array
    {
        if (\is_int($raw)) {
            return [$raw];
        }

        if (\is_string($raw)) {
            $raw = \explode(',', $raw);
        }

        if (\is_array($raw) && isset($raw['ids']) && \is_array($raw['ids'])) {
            $raw = $raw['ids'];
        }

        if (\is_array($raw)) {
            $list = [];
            foreach ($raw as $item) {
                if (\is_numeric($item)) {
                    $list[] = (int) $item;
                }
            }

            return $list;
        }

        return [];
    }

    protected function resolveLocale(?string $locale, ?RequestStack $requestStack = null): string
    {
        if (null !== $locale && '' !== \trim($locale)) {
            return \trim($locale);
        }

        if (null !== $requestStack) {
            $request = $requestStack->getCurrentRequest();
            if (null !== $request && '' !== $request->getLocale()) {
                return $request->getLocale();
            }
        }

        return 'en';
    }
}
