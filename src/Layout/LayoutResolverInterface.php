<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

interface LayoutResolverInterface
{
    /**
     * @param array<string, mixed>|null $layoutData
     *
     * @return array<string, string>
     */
    public function resolveClasses(?array $layoutData): array;

    /**
     * @param array<string, mixed>|null $layoutData
     */
    public function resolveContainerClass(?array $layoutData): string;

    /**
     * @param array<string, mixed>|null $layoutData
     */
    public function resolvePaddingClass(?array $layoutData): string;

    public function resolveClass(string $group, ?string $value, string $default = ''): string;

    /**
     * @param array<string, mixed>|null $props
     */
    public function resolveWrapperClasses(?array $props, string $extraClass = ''): string;

    /**
     * @param array<string, mixed>|null $layoutData
     *
     * @return array<string, string>
     */
    public function resolveAttributes(?array $layoutData): array;
}
