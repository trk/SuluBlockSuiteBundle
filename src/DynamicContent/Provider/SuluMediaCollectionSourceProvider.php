<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;

/**
 * Built-in Dynamic Content source provider for Sulu Media Collections & Media Items.
 */
final class SuluMediaCollectionSourceProvider implements DynamicContentSourceProviderInterface
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
            'tr' => 'Sulu Medya Koleksiyonu (Media Collection)',
            'de' => 'Sulu-Mediensammlung (Media Collection)',
            'ru' => 'Медиаколлекция Sulu (Media Collection)',
            'es' => 'Colección multimedia de Sulu (Media Collection)',
            'fr' => 'Collection média Sulu (Media Collection)',
            default => 'Sulu Media Collection',
        };
    }

    public function isMultiItem(): bool
    {
        return true;
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'image', 'label' => 'Media Image / Object', 'type' => 'single_media_selection'],
            ['name' => 'title', 'label' => 'Media Title', 'type' => 'text_line'],
            ['name' => 'headline', 'label' => 'Media Title (Headline)', 'type' => 'text_line'],
            ['name' => 'description', 'label' => 'Media Description', 'type' => 'text_area'],
            ['name' => 'summary', 'label' => 'Media Description (Summary)', 'type' => 'text_area'],
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

        $resolvedLocale = $this->resolveLocale($locale);
        $mediaId = $config['media_id'] ?? ($config['id'] ?? null);

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

        $resolvedLocale = $this->resolveLocale($locale);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        $filter = [];

        $collectionId = $query['collection_id'] ?? ($query['collection'] ?? ($query['parent_id'] ?? null));
        if (\is_numeric($collectionId) && (int) $collectionId > 0) {
            $filter['collection'] = (int) $collectionId;
        }

        if (isset($query['ids']) && \is_array($query['ids']) && [] !== $query['ids']) {
            $ids = [];
            foreach ($query['ids'] as $id) {
                if (\is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
            if ([] !== $ids) {
                $filter['ids'] = $ids;
            }
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
