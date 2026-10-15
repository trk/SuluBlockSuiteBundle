<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;

/**
 * Built-in Dynamic Content source provider for Sulu Media Collections & Single Media Items.
 */
final class SuluMediaCollectionSourceProvider extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly ?MediaManagerInterface $mediaManager = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getKey(): string
    {
        return 'sulu_media_collection';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Medya Koleksiyonu',
            'de' => 'Mediensammlung',
            'ru' => 'Медиаколлекция',
            'es' => 'Colección multimedia',
            'fr' => 'Collection média',
            default => 'Media Collection',
        };
    }

    public function getSingleKey(): string
    {
        return 'single_media';
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Tekil Medya Seçimi',
            'de' => 'Einzelne Medienauswahl',
            'ru' => 'Выбор одного медиафайла',
            'es' => 'Selección de medio único',
            'fr' => 'Sélection d’un média unique',
            default => 'Single Media Selection',
        };
    }

    public function getSingleConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'media_id',
                type: 'single_media_selection',
                label: 'sulu_block_suite.dynamic_single_media',
                description: 'sulu_block_suite.dynamic_single_media_desc',
                required: true,
                colSpan: 12,
            ),
        ];
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'collection_id',
                type: 'single_collection_selection',
                label: 'sulu_block_suite.dynamic_collection_id',
                description: 'sulu_block_suite.dynamic_collection_id_desc',
                required: false,
                colSpan: 6,
            ),
            $this->createSelectionField(
                name: 'ids',
                type: 'media_selection',
                label: 'sulu_block_suite.dynamic_media_selection',
                description: 'sulu_block_suite.dynamic_media_selection_desc',
                required: false,
                colSpan: 6,
            ),
            ...$this->createPaginationAndSortFields(24),
        ];
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'image', 'label' => 'Media Image', 'type' => 'single_media_selection'],
            ['name' => 'title', 'label' => 'Media Title', 'type' => 'text_line'],
            ['name' => 'headline', 'label' => 'Headline', 'type' => 'text_line'],
            ['name' => 'description', 'label' => 'Media Description', 'type' => 'text_area'],
            ['name' => 'summary', 'label' => 'Summary', 'type' => 'text_area'],
            ['name' => 'caption', 'label' => 'Media Caption', 'type' => 'text_line'],
            ['name' => 'url', 'label' => 'Media Direct URL', 'type' => 'url'],
            ['name' => 'link', 'label' => 'Media Link', 'type' => 'link'],
            ['name' => 'copyright', 'label' => 'Copyright', 'type' => 'text_line'],
            ['name' => 'credits', 'label' => 'Credits', 'type' => 'text_line'],
        ];
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->mediaManager) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $mediaId = $config['media_id'] ?? ($config['id'] ?? ($config['page_id'] ?? null));
        if (\is_array($mediaId) && isset($mediaId['id'])) {
            $mediaId = $mediaId['id'];
        }

        if (\is_numeric($mediaId)) {
            try {
                $media = $this->mediaManager->getById((int) $mediaId, $resolvedLocale);

                return $this->normalizeMedia($media);
            } catch (\Throwable) {
                return null;
            }
        }

        $collection = $this->resolveCollection(\array_merge($config, ['limit' => 1]), $contextPage, $resolvedLocale);

        return $collection[0] ?? null;
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        if (null === $this->mediaManager) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        $filter = [];

        $collectionId = $query['collection_id'] ?? ($query['collection'] ?? ($query['parent_id'] ?? ($query['page_id'] ?? null)));
        if (\is_numeric($collectionId) && (int) $collectionId > 0) {
            $filter['collection'] = (int) $collectionId;
        }

        $ids = $this->normalizeIntList($query['ids'] ?? ($query['media_ids'] ?? null));
        if ([] !== $ids) {
            $filter['ids'] = $ids;
        }

        if (isset($query['types']) && \is_string($query['types']) && '' !== \trim($query['types'])) {
            $filter['types'] = \array_values(\array_filter(\array_map('trim', \explode(',', $query['types']))));
        }

        $limit = isset($query['limit']) && \is_numeric($query['limit']) && (int) $query['limit'] > 0
            ? (int) $query['limit']
            : 24;
        $offset = isset($query['offset']) && \is_numeric($query['offset']) && (int) $query['offset'] >= 0
            ? (int) $query['offset']
            : 0;

        try {
            $medias = $this->mediaManager->get($resolvedLocale, $filter, $limit, $offset);
            $result = [];
            foreach ($medias as $media) {
                $result[] = $this->normalizeMedia($media);
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMedia(Media $media): array
    {
        /** @var string|null $title */
        $title = $media->getTitle();
        /** @var string|null $description */
        $description = $media->getDescription();
        /** @var string|null $url */
        $url = $media->getUrl();
        /** @var array<string, string> $formats */
        $formats = $media->getFormats();
        /** @var string|null $copyright */
        $copyright = $media->getCopyright();
        /** @var string|null $credits */
        $credits = $media->getCredits();
        /** @var string|null $name */
        $name = $media->getName();
        /** @var string|null $mimeType */
        $mimeType = $media->getMimeType();

        $caption = (null !== $description && '' !== \trim($description)) ? $description : $title;

        $imagePayload = [
            'id' => $media->getId(),
            'url' => $url,
            'formats' => $formats,
            'title' => $title,
            'description' => $description,
        ];

        return [
            'id' => (string) $media->getId(),
            'title' => $title ?? $name,
            'headline' => $title ?? $name,
            'name' => $name,
            'description' => $description,
            'summary' => $description,
            'caption' => $caption,
            'url' => $url,
            'link' => $url,
            'image' => $imagePayload,
            'copyright' => $copyright,
            'credits' => $credits,
            'mime_type' => $mimeType,
        ];
    }
}
