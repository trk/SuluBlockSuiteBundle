<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;

/**
 * Built-in Dynamic Content source provider for Sulu Pages
 * (Current Page, Parent Page, Single Page Selection, Multiple Page Selection,
 * Child Pages, Sibling Pages, and Smart Content Page Queries).
 */
final class SuluPageSourceProvider extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly ?PageSourceResolverInterface $sourceResolver = null,
    ) {
    }

    public function getKey(): string
    {
        return 'sulu_pages';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Sayfalar',
            'de' => 'Seiten',
            'ru' => 'Страницы',
            'es' => 'Páginas',
            'fr' => 'Pages',
            default => 'Pages',
        };
    }

    public function getSourceDescriptors(string $locale = 'en'): array
    {
        $lang = \strtolower(\substr($locale, 0, 2));

        return [
            [
                'key' => 'current_page',
                'label' => match ($lang) {
                    'tr' => 'Mevcut Sayfa',
                    'de' => 'Aktuelle Seite',
                    'ru' => 'Текущая страница',
                    'es' => 'Página actual',
                    'fr' => 'Page actuelle',
                    default => 'Current Page',
                },
                'multi_item' => false,
                'config_fields' => [],
            ],
            [
                'key' => 'parent_page',
                'label' => match ($lang) {
                    'tr' => 'Üst Sayfa',
                    'de' => 'Übergeordnete Seite',
                    'ru' => 'Родительская страница',
                    'es' => 'Página superior',
                    'fr' => 'Page parente',
                    default => 'Parent Page',
                },
                'multi_item' => false,
                'config_fields' => [],
            ],
            [
                'key' => 'specific_page',
                'label' => match ($lang) {
                    'tr' => 'Tekil Sayfa Seçimi',
                    'de' => 'Einzelne Seitenauswahl',
                    'ru' => 'Выбор одной страницы',
                    'es' => 'Selección de página única',
                    'fr' => 'Sélection de page unique',
                    default => 'Single Page Selection',
                },
                'multi_item' => false,
                'config_fields' => [
                    $this->createSelectionField(
                        name: 'page_id',
                        type: 'single_page_selection',
                        label: 'sulu_block_suite.dynamic_page_id',
                        description: 'sulu_block_suite.dynamic_page_id_desc',
                        required: true,
                        colSpan: 12,
                        triggerSourceFieldsReload: true,
                    ),
                ],
            ],
            [
                'key' => 'page_selection',
                'label' => match ($lang) {
                    'tr' => 'Çoklu Sayfa Seçimi',
                    'de' => 'Mehrfache Seitenauswahl',
                    'ru' => 'Выбор нескольких страниц',
                    'es' => 'Selección de múltiples páginas',
                    'fr' => 'Sélection de pages multiples',
                    default => 'Page Selection',
                },
                'multi_item' => true,
                'config_fields' => [
                    $this->createSelectionField(
                        name: 'uuids',
                        type: 'page_selection',
                        label: 'sulu_block_suite.dynamic_page_selection',
                        description: 'sulu_block_suite.dynamic_page_selection_desc',
                        required: true,
                        colSpan: 12,
                    ),
                    ...$this->createPaginationAndSortFields(10),
                ],
            ],
            [
                'key' => 'page_children',
                'label' => match ($lang) {
                    'tr' => 'Alt Sayfalar',
                    'de' => 'Unterseiten',
                    'ru' => 'Дочерние страницы',
                    'es' => 'Subpáginas',
                    'fr' => 'Sous-pages',
                    default => 'Child Pages',
                },
                'multi_item' => true,
                'config_fields' => [
                    $this->createSelectionField(
                        name: 'page_id',
                        type: 'single_page_selection',
                        label: 'sulu_block_suite.dynamic_page_id',
                        description: 'sulu_block_suite.dynamic_parent_page_id_desc',
                        required: false,
                        colSpan: 12,
                        triggerSourceFieldsReload: true,
                    ),
                    ...$this->createPaginationAndSortFields(10),
                    ...$this->createTaxonomyFilterFields(includeTemplateFilter: true, includeTags: true, includeCategories: true),
                ],
            ],
            [
                'key' => 'sibling_pages',
                'label' => match ($lang) {
                    'tr' => 'Kardeş Sayfalar',
                    'de' => 'Geschwisterseiten',
                    'ru' => 'Соседние страницы',
                    'es' => 'Páginas hermanas',
                    'fr' => 'Pages sœurs',
                    default => 'Sibling Pages',
                },
                'multi_item' => true,
                'config_fields' => [
                    ...$this->createPaginationAndSortFields(10),
                    ...$this->createTaxonomyFilterFields(includeTemplateFilter: true, includeTags: true, includeCategories: true),
                    [
                        'name' => 'exclude_current',
                        'label' => 'sulu_block_suite.dynamic_exclude_current',
                        'type' => 'checkbox',
                        'default' => true,
                        'col_span' => 12,
                    ],
                ],
            ],
            [
                'key' => 'custom_query',
                'label' => match ($lang) {
                    'tr' => 'Akıllı Sayfa Sorgusu',
                    'de' => 'Smart-Content-Seitenabfrage',
                    'ru' => 'Умный запрос страниц',
                    'es' => 'Consulta inteligente de páginas',
                    'fr' => 'Requête intelligente de pages',
                    default => 'Smart Content Pages',
                },
                'multi_item' => true,
                'config_fields' => [
                    ...$this->createPaginationAndSortFields(10),
                    ...$this->createTaxonomyFilterFields(includeTemplateFilter: true, includeTags: true, includeCategories: true),
                ],
            ],
        ];
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
            ['name' => 'headline', 'label' => 'Headline', 'type' => 'string'],
            ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'string'],
            ['name' => 'summary', 'label' => 'Summary', 'type' => 'text'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['name' => 'image', 'label' => 'Image', 'type' => 'media'],
            ['name' => 'url', 'label' => 'Page URL', 'type' => 'link'],
            ['name' => 'link', 'label' => 'Page Link', 'type' => 'link'],
            ['name' => 'badge', 'label' => 'Badge', 'type' => 'string'],
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'array'],
            ['name' => 'template', 'label' => 'Template Key', 'type' => 'string'],
            ['name' => 'date', 'label' => 'Publish Date', 'type' => 'date'],
            ['name' => 'author', 'label' => 'Author', 'type' => 'string'],
        ];
    }

    public function resolveSourceFields(string $locale = 'en', array $context = []): array
    {
        $fields = $this->getSourceFields($locale);

        $pageId = $context['page_id'] ?? ($context['id'] ?? null);
        if (\is_string($pageId) && '' !== \trim($pageId) && null !== $this->sourceResolver) {
            $pageData = $this->sourceResolver->resolvePage(\trim($pageId), $locale);

            if (null !== $pageData) {
                $existingNames = \array_column($fields, 'name');
                foreach ($pageData as $key => $value) {
                    if (\in_array($key, $existingNames, true) || \str_starts_with($key, '_') || 'id' === $key || 'uuid' === $key) {
                        continue;
                    }

                    $type = 'string';
                    if (\is_array($value) && isset($value['id'])) {
                        $type = 'media';
                    } elseif (\is_array($value)) {
                        $type = 'array';
                    } elseif (\is_bool($value)) {
                        $type = 'boolean';
                    }

                    $fields[] = [
                        'name' => $key,
                        'label' => \ucwords(\str_replace(['_', '-'], ' ', $key)),
                        'type' => $type,
                    ];
                }
            }
        }

        return $fields;
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->sourceResolver) {
            return null;
        }

        $sourceType = isset($config['source_type']) && \is_string($config['source_type'])
            ? $config['source_type']
            : 'specific_page';

        if ('parent_page' === $sourceType) {
            $targetPage = $this->resolveTargetOrContext($contextPage, $config['page_id'] ?? null);

            return $this->sourceResolver->resolveParent($targetPage, $locale);
        }

        /** @var mixed $rawTargetPage */
        $rawTargetPage = 'current_page' === $sourceType
            ? $contextPage
            : ($config['page_id'] ?? ($config['id'] ?? ($config['uuid'] ?? null)));

        if (\is_string($rawTargetPage) && '' === \trim($rawTargetPage)) {
            $rawTargetPage = null;
        }

        if (!\is_string($rawTargetPage) && !\is_object($rawTargetPage) && !\is_array($rawTargetPage)) {
            if ('current_page' === $sourceType) {
                $rawTargetPage = [];
            } else {
                return null;
            }
        }

        /** @var string|object|array<mixed, mixed> $targetPage */
        $targetPage = $rawTargetPage;

        return $this->sourceResolver->resolvePage($targetPage, $locale);
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        if (null === $this->sourceResolver) {
            return [];
        }

        $sourceType = isset($config['source_type']) && \is_string($config['source_type'])
            ? $config['source_type']
            : 'custom_query';

        if ('page_selection' === $sourceType) {
            $rawIds = $config['page_ids'] ?? ($config['uuids'] ?? ($config['ids'] ?? null));
            $queryOptions = $this->extractQueryOptions($config);
            $queryOptions['uuids'] = $rawIds;
            if (isset($config['limit']) && \is_numeric($config['limit'])) {
                $queryOptions['limit'] = (int) $config['limit'];
            }

            return $this->sourceResolver->resolveQuery($queryOptions, $locale);
        }

        if ('page_children' === $sourceType) {
            $rawParentId = $this->resolveTargetOrContext(
                $config['parent_id'] ?? ($config['page_id'] ?? null),
                $contextPage,
            );
            if (!\is_string($rawParentId) && !\is_object($rawParentId) && !\is_array($rawParentId)) {
                $rawParentId = [];
            }
            /** @var string|object|array<mixed, mixed> $parentId */
            $parentId = $rawParentId;
            $limit = isset($config['limit']) && \is_numeric($config['limit'])
                ? (int) $config['limit']
                : 50;

            $queryOptions = $this->extractQueryOptions($config);
            if ([] !== $queryOptions) {
                return $this->sourceResolver->resolveQuery(\array_merge($queryOptions, [
                    'parent_id' => $parentId,
                    'limit' => $limit,
                ]), $locale);
            }

            return $this->sourceResolver->resolveChildren($parentId, $locale, $limit);
        }

        if ('sibling_pages' === $sourceType) {
            $targetPage = $this->resolveTargetOrContext($contextPage, $config['page_id'] ?? null);
            $limit = isset($config['limit']) && \is_numeric($config['limit'])
                ? (int) $config['limit']
                : 50;
            $excludeCurrent = !isset($config['exclude_current']) || (bool) $config['exclude_current'];
            $queryOptions = $this->extractQueryOptions($config);

            return $this->sourceResolver->resolveSiblings($targetPage, $locale, $limit, $excludeCurrent, $queryOptions);
        }

        /** @var array<string, mixed> $query */
        $query = isset($config['query']) && \is_array($config['query'])
            ? $config['query']
            : [];
        $extraOptions = $this->extractQueryOptions($config);
        if ([] !== $extraOptions) {
            $query = \array_merge($extraOptions, $query);
        }
        if (isset($config['limit']) && \is_numeric($config['limit']) && !isset($query['limit'])) {
            $query['limit'] = (int) $config['limit'];
        }

        return $this->sourceResolver->resolveQuery($query, $locale);
    }

    /**
     * @return string|object|array<mixed, mixed>|null
     */
    private function resolveTargetOrContext(
        mixed $configuredTarget,
        mixed $fallbackTarget,
    ): string|object|array|null {
        if (\is_string($configuredTarget) && '' !== \trim($configuredTarget)) {
            return \trim($configuredTarget);
        }

        if (\is_object($configuredTarget) || (\is_array($configuredTarget) && [] !== $configuredTarget)) {
            return $configuredTarget;
        }

        if (\is_string($fallbackTarget) && '' !== \trim($fallbackTarget)) {
            return \trim($fallbackTarget);
        }

        if (\is_object($fallbackTarget) || \is_array($fallbackTarget)) {
            return $fallbackTarget;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dynamicConfig
     *
     * @return array<string, mixed>
     */
    private function extractQueryOptions(array $dynamicConfig): array
    {
        $options = [];
        $allowedKeys = [
            'sort_by',
            'sort_order',
            'offset',
            'template',
            'templates',
            'template_keys',
            'tag',
            'tags',
            'tag_names',
            'tag_ids',
            'category',
            'categories',
            'category_keys',
            'category_ids',
            'webspace_key',
            'descendant_of_id',
            'exclude_id',
            'exclude_ids',
            'uuids',
            'ids',
            'page_ids',
        ];

        foreach ($allowedKeys as $key) {
            if (isset($dynamicConfig[$key]) && '' !== $dynamicConfig[$key] && [] !== $dynamicConfig[$key]) {
                $options[$key] = $dynamicConfig[$key];
            }
        }

        return $options;
    }
}
