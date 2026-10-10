<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

interface PageLayoutProviderInterface
{
    /**
     * Resolves a layout snippet by key, title, slug, or UUID in the specified locale.
     *
     * @return array<string, mixed>|null
     */
    public function getLayout(string $identifier, ?string $locale = null): ?array;

    /**
     * Renders a layout by key, title, UUID, or pre-loaded snippet array.
     *
     * @param string|array<string, mixed>|null $layout
     * @param array<string, mixed> $context
     */
    public function renderLayout(string|array|null $layout, array $context = [], ?string $locale = null): string;

    /**
     * Resolves a reusable block snippet by UUID in the specified locale.
     *
     * @return array<string, mixed>|null
     */
    public function getReusableBlock(string $identifier, ?string $locale = null): ?array;
}
