<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge;

use Sulu\Bundle\AdminBundle\SmartContent\SmartContentProviderInterface;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Content\Application\ResourceLoader\ResourceLoaderProvider;

/**
 * Bridge between Block Suite Dynamic Content queries and Sulu 3's native
 * `SmartContentProviderInterface` (`sulu_content.smart_content_provider`) and
 * `ResourceLoaderProvider` (`sulu_content.resource_loader_provider`).
 *
 * Delegates filtering (categories, tags, templates/types, dataSource, webspace,
 * audience targeting, access control), sorting, and SQL pagination directly to
 * Sulu's SmartContent providers and batch-loads matched entities via Sulu's
 * cached ResourceLoaders.
 *
 * @phpstan-import-type SmartContentBaseFilters from SmartContentProviderInterface
 */
final class SuluSmartContentQueryBridge
{
    /**
     * @var array<string, SmartContentProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<int|string, SmartContentProviderInterface> $smartContentProviders
     */
    public function __construct(
        iterable $smartContentProviders = [],
        private readonly ?ResourceLoaderProvider $resourceLoaderProvider = null,
        private readonly ?TagManagerInterface $tagManager = null,
        private readonly ?RequestAnalyzerInterface $requestAnalyzer = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
        foreach ($smartContentProviders as $key => $provider) {
            $providerKey = \is_string($key) && '' !== \trim($key)
                ? \trim($key)
                : $provider->getType();
            $this->providers[$providerKey] = $provider;
            $this->providers[$provider->getType()] = $provider;
        }
    }

    public function hasProvider(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    public function getProvider(string $type): ?SmartContentProviderInterface
    {
        return $this->providers[$type] ?? null;
    }

    /**
     * @return array<string, SmartContentProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Executes `SmartContentProviderInterface::findFlatBy()` and returns the ordered list of resource IDs.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    public function queryFlatIds(
        string $providerType,
        array $config,
        string $locale,
        ?string $dataSource = null,
        bool $includeSubFolders = true,
        array $params = [],
    ): array {
        $provider = $this->getProvider($providerType);
        if (null === $provider) {
            return [];
        }

        $filters = $this->buildFilters($config, $locale, $dataSource, $includeSubFolders);
        $sortBys = $this->buildSortBys($config, $providerType);

        try {
            $flatRows = $provider->findFlatBy($filters, $sortBys, $params);
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach ($flatRows as $row) {
            $id = \trim((string) $row['id']);
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Batch-loads resources via Sulu's `ResourceLoaderProvider` (which uses `CachedResourceLoader`)
     * in the exact order of `$ids` and registers them in `ReferenceStoreInterface`.
     *
     * @param list<string|int> $ids
     * @param array<string, mixed> $params
     *
     * @return list<mixed>
     */
    public function loadOrderedResources(
        string $providerType,
        array $ids,
        string $locale,
        array $params = [],
    ): array {
        if ([] === $ids || null === $this->resourceLoaderProvider) {
            return [];
        }

        $provider = $this->getProvider($providerType);
        $loaderKey = $provider?->getResourceLoaderKey() ?? $providerType;
        $resourceKey = $provider?->getType() ?? $providerType;

        $loader = $this->resourceLoaderProvider->getResourceLoader($loaderKey);
        if (null === $loader) {
            return [];
        }

        /** @var array<int, string> $stringIds */
        $stringIds = \array_map(static fn (string|int $id): string => (string) $id, $ids);

        try {
            $loadedByKey = $loader->load($stringIds, $locale, $params);
        } catch (\Throwable) {
            return [];
        }

        $ordered = [];
        foreach ($ids as $id) {
            $resource = $loadedByKey[$id] ?? ($loadedByKey[(string) $id] ?? null);
            if (null === $resource) {
                continue;
            }

            $this->referenceStore?->add((string) $id, $resourceKey);
            $ordered[] = $resource;
        }

        return $ordered;
    }

    public function recordReference(string $providerType, string $id): void
    {
        $trimmedId = \trim($id);
        if ('' === $trimmedId) {
            return;
        }

        $provider = $this->getProvider($providerType);
        $resourceKey = $provider?->getType() ?? $providerType;
        $this->referenceStore?->add($trimmedId, $resourceKey);
    }

    /**
     * Builds a normalized `SmartContentBaseFilters` array from Block Suite dynamic config.
     *
     * @param array<string, mixed> $config
     *
     * @return SmartContentBaseFilters
     */
    public function buildFilters(
        array $config,
        string $locale,
        ?string $dataSource = null,
        bool $includeSubFolders = true,
    ): array {
        $limit = isset($config['limit']) && \is_numeric($config['limit'])
            ? \max(1, \min(100, (int) $config['limit']))
            : 10;
        $offset = isset($config['offset']) && \is_numeric($config['offset'])
            ? \max(0, \min(500, (int) $config['offset']))
            : 0;

        $categories = $this->extractCategoryIds(
            $config['category_ids'] ?? ($config['categories'] ?? ($config['category_keys'] ?? ($config['category'] ?? null))),
        );
        $tags = $this->extractTagIds(
            $config['tag_ids'] ?? ($config['tags'] ?? ($config['tag_names'] ?? ($config['tag'] ?? null))),
        );
        $templateKeys = $this->extractStringList(
            $config['template_keys'] ?? ($config['templates'] ?? ($config['template'] ?? ($config['types'] ?? null))),
        );

        $resolvedDataSource = $dataSource;
        if (null === $resolvedDataSource) {
            $rawDataSource = $config['data_source'] ?? ($config['dataSource'] ?? ($config['collection_id'] ?? null));
            if (\is_scalar($rawDataSource) && '' !== \trim((string) $rawDataSource)) {
                $resolvedDataSource = \trim((string) $rawDataSource);
            }
        }

        $webspaceKey = isset($config['webspace_key']) && \is_string($config['webspace_key']) && '' !== \trim($config['webspace_key'])
            ? \trim($config['webspace_key'])
            : $this->requestAnalyzer?->getWebspace()?->getKey();

        $categoryOp = isset($config['category_operator']) && \is_string($config['category_operator']) && 'AND' === \strtoupper($config['category_operator'])
            ? 'AND'
            : 'OR';
        $tagOp = isset($config['tag_operator']) && \is_string($config['tag_operator']) && 'AND' === \strtoupper($config['tag_operator'])
            ? 'AND'
            : 'OR';

        /** @var SmartContentBaseFilters $filters */
        $filters = [
            'categories' => $categories,
            'categoryOperator' => $categoryOp,
            'websiteCategories' => [],
            'websiteCategoryOperator' => 'OR',
            'tags' => $tags,
            'tagOperator' => $tagOp,
            'websiteTags' => [],
            'websiteTagOperator' => 'OR',
            'types' => $templateKeys,
            'typesOperator' => 'OR',
            'templateKeys' => $templateKeys,
            'locale' => $locale,
            'dataSource' => $resolvedDataSource,
            'limit' => $limit,
            'offset' => $offset,
            'includeSubFolders' => $includeSubFolders,
            'excludeDuplicates' => false,
        ];

        if (null !== $webspaceKey && '' !== $webspaceKey) {
            $filters['webspaceKey'] = $webspaceKey;
        }

        return $filters;
    }

    /**
     * Maps Block Suite `sort_by` and `sort_order` to SmartContent `$sortBys`.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, 'asc'|'desc'>
     */
    public function buildSortBys(array $config, string $providerType = 'pages'): array
    {
        $rawSortBy = isset($config['sort_by']) && \is_string($config['sort_by'])
            ? \trim($config['sort_by'])
            : '';
        $rawOrder = isset($config['sort_order']) && \is_string($config['sort_order'])
            ? \strtolower(\trim($config['sort_order']))
            : 'desc';
        $direction = 'asc' === $rawOrder ? 'asc' : 'desc';

        if ('media' === $providerType) {
            $mediaColumn = match ($rawSortBy) {
                'title' => 'fileVersionMeta.title',
                'changed', 'modified' => 'media.changed',
                default => 'media.created',
            };

            return [$mediaColumn => $direction];
        }

        if ('contacts' === $providerType) {
            $contactColumn = match ($rawSortBy) {
                'firstName', 'first_name' => 'contact.firstName',
                default => 'contact.lastName',
            };

            return [$contactColumn => $direction];
        }

        if ('accounts' === $providerType) {
            return ['account.name' => $direction];
        }

        $sortColumn = match ($rawSortBy) {
            'title' => 'title',
            'created' => 'created',
            'changed', 'modified' => 'changed',
            'authored' => 'authored',
            'published', '' => 'published',
            default => $rawSortBy,
        };

        return [$sortColumn => $direction];
    }

    /**
     * @return list<int>
     */
    public function extractCategoryIds(mixed $raw): array
    {
        if (null === $raw || '' === $raw || [] === $raw) {
            return [];
        }

        $items = \is_string($raw) ? \explode(',', $raw) : (\is_array($raw) ? $raw : [$raw]);
        $ids = [];
        foreach ($items as $item) {
            if (\is_int($item) && $item > 0) {
                $ids[] = $item;
            } elseif (\is_string($item) && \ctype_digit(\trim($item))) {
                $intVal = (int) \trim($item);
                if ($intVal > 0) {
                    $ids[] = $intVal;
                }
            } elseif (\is_array($item) && isset($item['id']) && \is_numeric($item['id'])) {
                $intVal = (int) $item['id'];
                if ($intVal > 0) {
                    $ids[] = $intVal;
                }
            }
        }

        return \array_values(\array_unique($ids));
    }

    /**
     * @return list<int>
     */
    public function extractTagIds(mixed $raw): array
    {
        if (null === $raw || '' === $raw || [] === $raw) {
            return [];
        }

        $items = \is_string($raw) ? \explode(',', $raw) : (\is_array($raw) ? $raw : [$raw]);
        $ids = [];
        foreach ($items as $item) {
            if (\is_int($item) && $item > 0) {
                $ids[] = $item;
                continue;
            }

            if (\is_string($item)) {
                $trimmed = \trim($item);
                if ('' === $trimmed) {
                    continue;
                }
                if (\ctype_digit($trimmed)) {
                    $intVal = (int) $trimmed;
                    if ($intVal > 0) {
                        $ids[] = $intVal;
                    }
                    continue;
                }

                if (null !== $this->tagManager) {
                    try {
                        $tag = $this->tagManager->findByName($trimmed);
                        $tagId = $tag->getId();
                        if ($tagId > 0) {
                            $ids[] = $tagId;
                        }
                    } catch (\Throwable) {
                        // ignore unresolvable tag name
                    }
                }
            }
        }

        return \array_values(\array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function extractStringList(mixed $raw): array
    {
        if (null === $raw || '' === $raw || [] === $raw) {
            return [];
        }

        $items = \is_string($raw) ? \explode(',', $raw) : (\is_array($raw) ? $raw : []);
        $result = [];
        foreach ($items as $item) {
            if (\is_scalar($item)) {
                $trimmed = \trim((string) $item);
                if ('' !== $trimmed) {
                    $result[] = $trimmed;
                }
            }
        }

        return \array_values(\array_unique($result));
    }
}
