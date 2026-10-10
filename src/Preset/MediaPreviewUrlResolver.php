<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;

/**
 * Resolves a preview image URL from a media ID, media array, or raw string URL.
 */
final class MediaPreviewUrlResolver
{
    public function __construct(
        private readonly ?MediaManagerInterface $mediaManager = null,
    ) {
    }

    /**
     * Resolves a preview image URL.
     */
    public function resolve(mixed $previewImageData, string $locale): ?string
    {
        if (null === $previewImageData) {
            return null;
        }

        /** @var mixed $mediaId */
        $mediaId = \is_array($previewImageData) ? ($previewImageData['id'] ?? null) : $previewImageData;

        if ((\is_int($mediaId) || (\is_string($mediaId) && \is_numeric($mediaId))) && null !== $this->mediaManager) {
            try {
                $media = $this->mediaManager->getById((int) $mediaId, $locale);
                /** @var array<string, string> $formats */
                $formats = $media->getFormats();

                return $formats['sulu-260x'] ?? $formats['sulu-100x100'] ?? $media->getUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        if (\is_string($previewImageData) && '' !== \trim($previewImageData)) {
            return \trim($previewImageData);
        }

        return null;
    }
}
