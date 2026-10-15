<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Bundle\CategoryBundle\Api\Category;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;

/**
 * Built-in Dynamic Content source provider for Sulu Categories (Single & Collection).
 */
final class SuluCategorySourceProvider extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly ?CategoryManagerInterface $categoryManager = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getKey(): string
    {
        return 'sulu_categories';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Kategoriler',
            'de' => 'Kategorien',
            'ru' => 'Категории',
            'es' => 'Categorías',
            'fr' => 'Catégories',
            default => 'Categories',
        };
    }

    public function getSingleKey(): string
    {
        return 'single_category';
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Tekil Kategori Seçimi',
            'de' => 'Einzelne Kategorieauswahl',
            'ru' => 'Выбор одной категории',
            'es' => 'Selección de categoría única',
            'fr' => 'Sélection de catégorie unique',
            default => 'Single Category Selection',
        };
    }

    public function getSingleConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'category_id',
                type: 'single_category_selection',
                label: 'sulu_block_suite.dynamic_single_category',
                description: 'sulu_block_suite.dynamic_single_category_desc',
                required: true,
                colSpan: 12,
            ),
        ];
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'category_ids',
                type: 'category_selection',
                label: 'sulu_block_suite.dynamic_category_selection',
                description: 'sulu_block_suite.dynamic_category_selection_desc',
                required: false,
                colSpan: 6,
            ),
            $this->createSelectionField(
                name: 'parent_id',
                type: 'single_category_selection',
                label: 'sulu_block_suite.dynamic_parent_category',
                description: 'sulu_block_suite.dynamic_parent_category_desc',
                required: false,
                colSpan: 6,
            ),
            ...$this->createPaginationAndSortFields(50),
        ];
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => 'Category Name', 'type' => 'text_line'],
            ['name' => 'headline', 'label' => 'Headline', 'type' => 'text_line'],
            ['name' => 'description', 'label' => 'Category Description', 'type' => 'text_area'],
            ['name' => 'summary', 'label' => 'Summary', 'type' => 'text_area'],
            ['name' => 'key', 'label' => 'Category Key', 'type' => 'text_line'],
            ['name' => 'badge', 'label' => 'Badge', 'type' => 'text_line'],
            ['name' => 'image', 'label' => 'Category Media', 'type' => 'single_media_selection'],
        ];
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->categoryManager) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $rawCategoryId = $config['category_id'] ?? ($config['id'] ?? ($config['page_id'] ?? null));

        try {
            $entity = null;
            if (\is_numeric($rawCategoryId)) {
                $entity = $this->categoryManager->findById((int) $rawCategoryId);
            } elseif (isset($config['category_key']) && \is_string($config['category_key']) && '' !== \trim($config['category_key'])) {
                $entity = $this->categoryManager->findByKey(\trim($config['category_key']));
            } else {
                $collection = $this->resolveCollection(\array_merge($config, ['limit' => 1]), $contextPage, $resolvedLocale);

                return $collection[0] ?? null;
            }

            $apiObject = $this->categoryManager->getApiObject($entity, $resolvedLocale);

            return $this->normalizeCategory($apiObject);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        if (null === $this->categoryManager) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        try {
            /** @var list<CategoryInterface> $entities */
            $entities = [];

            $parentId = $query['parent_id'] ?? ($query['page_id'] ?? null);
            $parentKey = $query['parent_key'] ?? null;
            if (\is_array($parentKey)) {
                $parentKey = $parentKey[0] ?? null;
            }

            $intIds = $this->normalizeIntList($query['category_ids'] ?? ($query['ids'] ?? null));
            if ([] !== $intIds) {
                /** @var list<CategoryInterface> $entities */
                $entities = \array_values($this->categoryManager->findByIds($intIds));
            } elseif (\is_numeric($parentId) && (int) $parentId > 0) {
                /** @var list<CategoryInterface> $entities */
                $entities = \array_values($this->categoryManager->findChildrenByParentId((int) $parentId));
            } elseif (\is_string($parentKey) && '' !== \trim($parentKey)) {
                /** @var list<CategoryInterface> $entities */
                $entities = \array_values($this->categoryManager->findChildrenByParentKey(\trim($parentKey)));
            } elseif (\is_string($parentId) && '' !== \trim($parentId)) {
                /** @var list<CategoryInterface> $entities */
                $entities = \array_values($this->categoryManager->findChildrenByParentKey(\trim($parentId)));
            } else {
                /** @var list<CategoryInterface> $entities */
                $entities = \array_values($this->categoryManager->findChildrenByParentId(null));
            }

            $apiObjects = $this->categoryManager->getApiObjects($entities, $resolvedLocale);
            $result = [];
            foreach ($apiObjects as $apiCategory) {
                $result[] = $this->normalizeCategory($apiCategory);
            }

            $offset = isset($query['offset']) && \is_numeric($query['offset']) && (int) $query['offset'] > 0
                ? (int) $query['offset']
                : 0;
            $limit = isset($query['limit']) && \is_numeric($query['limit']) && (int) $query['limit'] > 0
                ? (int) $query['limit']
                : 50;

            if ($offset > 0 || \count($result) > $limit) {
                $result = \array_slice($result, $offset, $limit);
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCategory(Category $category): array
    {
        /** @var string|null $name */
        $name = $category->getName();
        $key = (string) $category->getKey();
        $title = (null !== $name && '' !== \trim($name)) ? $name : $key;
        $description = $category->getDescription();

        $medias = $category->getMedias();
        $firstImage = null;
        if ([] !== $medias) {
            $firstMedia = $medias[0];
            $firstImage = [
                'id' => $firstMedia->getId(),
                'url' => $firstMedia->getUrl(),
                'formats' => $firstMedia->getFormats(),
                'title' => $firstMedia->getTitle(),
            ];
        }

        return [
            'id' => (string) $category->getId(),
            'key' => $key,
            'name' => $title,
            'title' => $title,
            'headline' => $title,
            'description' => $description,
            'summary' => $description,
            'badge' => $key,
            'meta' => $key,
            'image' => $firstImage,
        ];
    }
}
