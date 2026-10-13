<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

interface PageSourceResolverInterface
{
    /**
     * Resolves a single page document/array/UUID by context or id.
     *
     * @param string|object|array<mixed, mixed>|null $page
     *
     * @return array<string, mixed>|null Returns associative array of page data or null if not found
     */
    public function resolvePage(string|object|array|null $page = null, ?string $locale = null): ?array;

    /**
     * Resolves child pages of a parent page.
     *
     * @param string|object|array<mixed, mixed> $parent
     *
     * @return list<array<string, mixed>>
     */
    public function resolveChildren(string|object|array $parent, ?string $locale = null, int $limit = 50): array;

    /**
     * Resolves pages matching category / tag / custom query filters.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<array<string, mixed>>
     */
    public function resolveQuery(array $criteria, ?string $locale = null): array;

    /**
     * Resolves the parent page of the given page (or of the current page when null).
     *
     * @param string|object|array<mixed, mixed>|null $page
     *
     * @return array<string, mixed>|null
     */
    public function resolveParent(string|object|array|null $page = null, ?string $locale = null): ?array;

    /**
     * Resolves sibling pages sharing the same parent as the given page (or current page).
     *
     * @param string|object|array<mixed, mixed>|null $page
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public function resolveSiblings(
        string|object|array|null $page = null,
        ?string $locale = null,
        int $limit = 50,
        bool $excludeCurrent = true,
        array $options = [],
    ): array;
}
