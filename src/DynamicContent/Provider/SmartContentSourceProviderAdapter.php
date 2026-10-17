<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Bundle\AdminBundle\SmartContent\SmartContentProviderInterface;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;

/**
 * Automatic adapter that exposes any Sulu 3 `SmartContentProviderInterface`
 * (`sulu_content.smart_content_provider` — e.g. `snippets`, `contacts`, `accounts`,
 * or third-party bundle SmartContent providers) as a Block Suite Dynamic Content
 * collection source without requiring a custom provider class per bundle.
 */
final class SmartContentSourceProviderAdapter extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly SmartContentProviderInterface $smartContentProvider,
        private readonly SuluSmartContentQueryBridge $smartContentBridge,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?ContentAggregatorInterface $contentAggregator = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?string $sourceKeyOverride = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
    }

    public function getProviderType(): string
    {
        return $this->smartContentProvider->getType();
    }

    public function getKey(): string
    {
        if (null !== $this->sourceKeyOverride && '' !== \trim($this->sourceKeyOverride)) {
            return \trim($this->sourceKeyOverride);
        }

        $type = $this->smartContentProvider->getType();

        return \str_starts_with($type, 'sulu_') ? $type : 'sulu_' . $type;
    }

    public function getLabel(string $locale = 'en'): string
    {
        $lang = \strtolower(\substr($locale, 0, 2));
        $type = $this->smartContentProvider->getType();

        return match ($type) {
            'snippets' => match ($lang) {
                'tr' => 'Snippet Koleksiyonu (Smart Content)',
                'de' => 'Snippets (Smart Content)',
                'ru' => 'Сниппеты (Smart Content)',
                'es' => 'Fragmentos (Smart Content)',
                'fr' => 'Extraits (Smart Content)',
                default => 'Snippets (Smart Content)',
            },
            'contacts' => match ($lang) {
                'tr' => 'Kişiler (Smart Content)',
                'de' => 'Kontakte (Smart Content)',
                'ru' => 'Контакты (Smart Content)',
                'es' => 'Contactos (Smart Content)',
                'fr' => 'Contacts (Smart Content)',
                default => 'Contacts (Smart Content)',
            },
            'accounts' => match ($lang) {
                'tr' => 'Kurumlar / Hesaplar (Smart Content)',
                'de' => 'Organisationen (Smart Content)',
                'ru' => 'Организации (Smart Content)',
                'es' => 'Cuentas (Smart Content)',
                'fr' => 'Comptes (Smart Content)',
                default => 'Accounts (Smart Content)',
            },
            default => \ucwords(\str_replace(['_', '-'], ' ', $type)) . ' (Smart Content)',
        };
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        try {
            $configuration = $this->smartContentProvider->getConfiguration();
            $hasTypes = $configuration->hasTypes();
            $hasTags = $configuration->hasTags();
            $hasCategories = $configuration->hasCategories();
        } catch (\Throwable) {
            $hasTypes = false;
            $hasTags = true;
            $hasCategories = true;
        }

        return [
            ...$this->createPaginationAndSortFields(10),
            ...$this->createTaxonomyFilterFields(
                includeTemplateFilter: $hasTypes,
                includeTags: $hasTags,
                includeCategories: $hasCategories,
            ),
        ];
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        $fields = [
            ['name' => 'title', 'label' => $this->translateFieldLabel('title', $locale), 'type' => 'text_line'],
            ['name' => 'headline', 'label' => $this->translateFieldLabel('headline', $locale), 'type' => 'text_line'],
            ['name' => 'subtitle', 'label' => $this->translateFieldLabel('subtitle', $locale), 'type' => 'text_line'],
            ['name' => 'summary', 'label' => $this->translateFieldLabel('summary', $locale), 'type' => 'text_area'],
            ['name' => 'description', 'label' => $this->translateFieldLabel('description', $locale), 'type' => 'text_editor'],
            ['name' => 'image', 'label' => $this->translateFieldLabel('image', $locale), 'type' => 'single_media_selection'],
            ['name' => 'url', 'label' => $this->translateFieldLabel('url', $locale), 'type' => 'url'],
            ['name' => 'link', 'label' => $this->translateFieldLabel('link', $locale), 'type' => 'link'],
            ['name' => 'badge', 'label' => $this->translateFieldLabel('badge', $locale), 'type' => 'text_line'],
        ];

        $type = $this->smartContentProvider->getType();
        if ('contacts' === $type || 'accounts' === $type) {
            $fields[] = ['name' => 'email', 'label' => 'Email', 'type' => 'text_line'];
            $fields[] = ['name' => 'phone', 'label' => 'Phone', 'type' => 'text_line'];
        }

        return $fields;
    }

    public function resolveSingle(
        array $config,
        string|object|array|null $contextPage = null,
        ?string $locale = null,
    ): ?array {
        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $providerType = $this->smartContentProvider->getType();

        $directEntity = $config['entity'] ?? (\is_object($contextPage) ? $contextPage : null);
        if (\is_object($directEntity)) {
            $normalized = $this->normalizeLoadedResource($directEntity, $resolvedLocale);
            if (null !== $normalized) {
                $resolvedId = $normalized['uuid'] ?? ($normalized['id'] ?? null);
                if (\is_scalar($resolvedId) && '' !== \trim((string) $resolvedId)) {
                    $this->recordReference(\trim((string) $resolvedId));
                }

                return $normalized;
            }
        }

        $rawId = $config['id'] ?? ($config['uuid'] ?? ($config[$providerType . '_id'] ?? null));
        if (\is_scalar($rawId) && '' !== \trim((string) $rawId)) {
            $id = \trim((string) $rawId);
            $resources = $this->smartContentBridge->loadOrderedResources(
                providerType: $providerType,
                ids: [$id],
                locale: $resolvedLocale,
            );

            if ([] !== $resources) {
                $normalized = $this->normalizeLoadedResource($resources[0], $resolvedLocale);
                if (null !== $normalized) {
                    $this->recordReference($id);

                    return $normalized;
                }
            }
        }

        $collection = $this->resolveCollection(\array_merge($config, ['limit' => 1]), $contextPage, $locale);
        $single = $collection[0] ?? null;
        if (null !== $single) {
            $resolvedId = $single['uuid'] ?? ($single['id'] ?? null);
            if (\is_scalar($resolvedId) && '' !== \trim((string) $resolvedId)) {
                $this->recordReference(\trim((string) $resolvedId));
            }
        }

        return $single;
    }

    public function resolveCollection(
        array $config,
        string|object|array|null $contextPage = null,
        ?string $locale = null,
    ): array {
        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $providerType = $this->smartContentProvider->getType();

        $ids = $this->smartContentBridge->queryFlatIds(
            providerType: $providerType,
            config: $config,
            locale: $resolvedLocale,
        );

        if ([] === $ids) {
            return [];
        }

        $resources = $this->smartContentBridge->loadOrderedResources(
            providerType: $providerType,
            ids: $ids,
            locale: $resolvedLocale,
        );

        $items = [];
        foreach ($resources as $resource) {
            $normalized = $this->normalizeLoadedResource($resource, $resolvedLocale);
            if (null !== $normalized) {
                if (null !== $this->referenceStore) {
                    $resolvedId = $normalized['uuid'] ?? ($normalized['id'] ?? null);
                    if (\is_scalar($resolvedId) && '' !== \trim((string) $resolvedId)) {
                        $this->referenceStore->add(\trim((string) $resolvedId), $providerType);
                    }
                }
                $items[] = $normalized;
            }
        }

        return $items;
    }

    private function recordReference(string $id): void
    {
        $trimmedId = \trim($id);
        if ('' === $trimmedId) {
            return;
        }

        $providerType = $this->smartContentProvider->getType();
        if (null !== $this->referenceStore) {
            $this->referenceStore->add($trimmedId, $providerType);

            return;
        }

        $this->smartContentBridge->recordReference($providerType, $trimmedId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeLoadedResource(mixed $resource, string $locale): ?array
    {
        if (\is_array($resource)) {
            /** @var array<string, mixed> $resource */
            return $this->ensureStandardKeys($resource);
        }

        if ($resource instanceof ContentRichEntityInterface) {
            return $this->normalizeContentRichEntity($resource, $locale);
        }

        if (\is_object($resource)) {
            return $this->normalizeGenericObject($resource);
        }

        return null;
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $entity
     *
     * @return array<string, mixed>|null
     */
    private function normalizeContentRichEntity(ContentRichEntityInterface $entity, string $locale): ?array
    {
        $dimensionContent = null;
        if (null !== $this->contentAggregator) {
            try {
                $dimensionContent = $this->contentAggregator->aggregate($entity, [
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                    'version' => DimensionContentInterface::CURRENT_VERSION,
                ]);
            } catch (\Throwable) {
                $dimensionContent = null;
            }
        }

        if (null === $dimensionContent) {
            foreach ($entity->getDimensionContents() as $dc) {
                if (
                    DimensionContentInterface::STAGE_LIVE === $dc->getStage()
                    && DimensionContentInterface::CURRENT_VERSION === $dc->getVersion()
                    && $dc->getLocale() === $locale
                ) {
                    $dimensionContent = $dc;
                    break;
                }
            }
        }

        if (null === $dimensionContent) {
            return null;
        }

        $data = [];
        if (null !== $this->contentResolver) {
            try {
                $resolved = $this->contentResolver->resolve($dimensionContent);
                $data = $resolved['content'];
            } catch (\Throwable) {
                $data = [];
            }
        }

        if ([] === $data && $dimensionContent instanceof TemplateInterface) {
            $data = $dimensionContent->getTemplateData();
        }

        if (\method_exists($dimensionContent, 'getTitle')) {
            $title = $dimensionContent->getTitle();
            if (\is_string($title) && '' !== \trim($title) && !isset($data['title'])) {
                $data['title'] = $title;
            }
        }

        $data['id'] = (string) $entity->getId();
        if (\method_exists($entity, 'getUuid')) {
            $rawUuid = $entity->getUuid();
            if (\is_scalar($rawUuid)) {
                $data['uuid'] = (string) $rawUuid;
            }
        }

        return $this->ensureStandardKeys($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeGenericObject(object $object): array
    {
        $data = [];
        if (\method_exists($object, 'toArray')) {
            $raw = $object->toArray();
            if (\is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $data = $raw;
            }
        } elseif ($object instanceof \JsonSerializable) {
            $raw = $object->jsonSerialize();
            if (\is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $data = $raw;
            }
        }

        $getterMap = [
            'id' => ['getId', 'getUuid'],
            'title' => ['getTitle', 'getFullName', 'getName'],
            'headline' => ['getHeadline', 'getTitle', 'getFullName', 'getName'],
            'subtitle' => ['getSubtitle', 'getPosition', 'getCorporation'],
            'summary' => ['getSummary', 'getNote', 'getDescription'],
            'description' => ['getDescription', 'getNote'],
            'url' => ['getUrl', 'getMainUrl'],
            'email' => ['getMainEmail', 'getEmail'],
            'phone' => ['getMainPhone', 'getPhone'],
            'image' => ['getAvatar', 'getLogo', 'getImage'],
        ];

        foreach ($getterMap as $field => $methods) {
            if (isset($data[$field]) && '' !== $data[$field]) {
                continue;
            }
            foreach ($methods as $method) {
                if (!\method_exists($object, $method)) {
                    continue;
                }
                try {
                    $value = $object->{$method}();
                } catch (\Throwable) {
                    continue;
                }
                if (null !== $value && '' !== $value && [] !== $value) {
                    $data[$field] = $value;
                    break;
                }
            }
        }

        return $this->ensureStandardKeys($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function ensureStandardKeys(array $data): array
    {
        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : '';
        if ('' === $title && isset($data['name']) && \is_string($data['name'])) {
            $title = $data['name'];
            $data['title'] = $title;
        }
        if (!isset($data['headline']) || '' === $data['headline']) {
            $data['headline'] = $title;
        }
        if (!isset($data['description']) && isset($data['summary']) && \is_string($data['summary'])) {
            $data['description'] = $data['summary'];
        }
        if (!isset($data['summary']) && isset($data['description']) && \is_string($data['description'])) {
            $data['summary'] = $data['description'];
        }
        if (!isset($data['link']) && isset($data['url']) && \is_string($data['url'])) {
            $data['link'] = $data['url'];
        }

        return $data;
    }
}
