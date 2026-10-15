<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Bundle\CategoryBundle\Api\Category;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;

/**
 * Built-in Dynamic Content source provider for Sulu Categories.
 */
final class SuluCategorySourceProvider implements DynamicContentSourceProviderInterface
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
            'tr' => 'Sulu Kategorileri (Categories)',
            'de' => 'Sulu-Kategorien (Categories)',
            'ru' => 'Категории Sulu (Categories)',
            'es' => 'Categorías de Sulu (Categories)',
            'fr' => 'Catégories Sulu (Categories)',
            default => 'Sulu Categories',
        };
    }

    public function isMultiItem(): bool
    {
        return true;
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => 'Category Name / Title', 'type' => 'text_line'],
            ['name' => 'headline', 'label' => 'Category Name (Headline)', 'type' => 'text_line'],
            ['name' => 'description', 'label' => 'Category Description', 'type' => 'text_area'],
            ['name' => 'summary', 'label' => 'Category Description (Summary)', 'type' => 'text_area'],
            ['name' => 'key', 'label' => 'Category Key', 'type' => 'text_line'],
            ['name' => 'badge', 'label' => 'Category Key (Badge)', 'type' => 'text_line'],
            ['name' => 'image', 'label' => 'Category Media / Image', 'type' => 'single_media_selection'],
        ];
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->categoryManager) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale);

        try {
            $entity = null;
            if (isset($config['category_id']) && \is_numeric($config['category_id'])) {
                $entity = $this->categoryManager->findById((int) $config['category_id']);
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

        $resolvedLocale = $this->resolveLocale($locale);
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

            $categoryIds = $query['category_ids'] ?? null;
            if (\is_string($categoryIds) && '' !== \trim($categoryIds)) {
                $categoryIds = \explode(',', $categoryIds);
            }
            if (\is_array($categoryIds) && [] !== $categoryIds) {
                $intIds = [];
                foreach ($categoryIds as $id) {
                    if (\is_numeric($id)) {
                        $intIds[] = (int) $id;
                    }
                }
                if ([] !== $intIds) {
                    /** @var list<CategoryInterface> $entities */
                    $entities = \array_values($this->categoryManager->findByIds($intIds));
                }
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

    private function resolveLocale(?string $locale): string
    {
        if (null !== $locale && '' !== \trim($locale)) {
            return \trim($locale);
        }

        if (null !== $this->requestStack) {
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request && '' !== $request->getLocale()) {
                return $request->getLocale();
            }
        }

        return 'en';
    }
}
