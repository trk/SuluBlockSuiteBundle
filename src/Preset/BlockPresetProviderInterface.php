<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

interface BlockPresetProviderInterface
{
    /**
     * @return list<array{id: string, title: string, description: ?string, preview_image?: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    public function getPresets(string $blockType, string $locale = 'en', ?string $webspace = null): array;

    public function clearCache(?string $blockType = null, ?string $locale = null): void;
}
