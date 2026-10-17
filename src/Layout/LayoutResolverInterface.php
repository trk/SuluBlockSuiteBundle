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

    public function resolveClass(string $group, ?string $value, string $default = ''): string;

    /**
     * @param array<string, mixed>|null $layoutData
     *
     * @return array<string, string>
     */
    public function resolveAttributes(?array $layoutData): array;
}
