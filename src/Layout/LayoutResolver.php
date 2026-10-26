<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

final class LayoutResolver implements LayoutResolverInterface
{
    public const DEFAULT_MAPPINGS = [];

    private const STRUCTURAL_KEYS = ['blocks', 'title', 'id', 'uuid', 'description', 'key', 'webspace', 'target_template', 'page_template'];

    /**
     * @var array<string, array<string, string>>
     */
    private array $mappings;

    /**
     * @var array<string, string>
     */
    private array $defaults;

    /**
     * @param array<string, array<string, string>> $mappings
     * @param array<string, scalar|null> $defaults
     */
    public function __construct(
        array $mappings = [],
        array $defaults = [],
    ) {
        $merged = self::DEFAULT_MAPPINGS;
        foreach ($mappings as $group => $groupMappings) {
            foreach ($groupMappings as $optionKey => $optionValue) {
                $merged[$group][(string) $optionKey] = $optionValue;
            }
        }
        $this->mappings = $merged;

        $normalizedDefaults = [];
        foreach ($defaults as $key => $value) {
            if (null !== $value) {
                $normalizedDefaults[(string) $key] = (string) $value;
            }
        }
        $this->defaults = $normalizedDefaults;
    }

    public function resolveClasses(?array $layoutData): array
    {
        $classes = [];
        $merged = \array_merge($this->defaults, $layoutData ?? []);

        foreach ($merged as $group => $rawValue) {
            $groupKey = (string) $group;
            if (\in_array($groupKey, self::STRUCTURAL_KEYS, true)) {
                continue;
            }
            if (!\is_scalar($rawValue) && null !== $rawValue) {
                continue;
            }

            $val = null !== $rawValue && '' !== (string) $rawValue
                ? (string) $rawValue
                : ($this->defaults[$groupKey] ?? null);

            if (isset($this->mappings[$groupKey])) {
                $classes[$groupKey] = $this->resolveClass($groupKey, $val);
            } elseif (null !== $val) {
                $classes[$groupKey] = $val;
            }
        }

        // Dynamically resolve any other configured mapping groups
        foreach ($this->mappings as $group => $options) {
            if (!\array_key_exists($group, $classes)) {
                $val = isset($layoutData[$group]) && \is_scalar($layoutData[$group])
                    ? (string) $layoutData[$group]
                    : ($this->defaults[$group] ?? null);
                $classes[$group] = $this->resolveClass($group, $val);
            }
        }

        return $classes;
    }

    public function resolveClass(string $group, ?string $value, string $default = ''): string
    {
        $effectiveValue = (null !== $value && '' !== $value)
            ? $value
            : ($this->defaults[$group] ?? null);

        if (null === $effectiveValue || '' === $effectiveValue) {
            return $this->mappings[$group]['default'] ?? $default;
        }

        if (isset($this->mappings[$group][$effectiveValue])) {
            return $this->mappings[$group][$effectiveValue];
        }

        $defaultKey = $this->defaults[$group] ?? null;
        if (null !== $defaultKey && '' !== $defaultKey && isset($this->mappings[$group][$defaultKey])) {
            return $this->mappings[$group][$defaultKey];
        }

        return $this->mappings[$group]['default'] ?? $default;
    }

    public function resolveAttributes(?array $layoutData): array
    {
        if (null === $layoutData && [] === $this->defaults) {
            return [];
        }

        $merged = \array_merge($this->defaults, $layoutData ?? []);
        $attributes = [];
        foreach ($merged as $key => $value) {
            $keyStr = (string) $key;
            if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $keyStr)) {
                continue;
            }
            if (\is_scalar($value) && '' !== (string) $value && !\in_array($keyStr, self::STRUCTURAL_KEYS, true)) {
                $attrName = 'data-layout-' . \str_replace('_', '-', $keyStr);
                $attributes[$attrName] = (string) $value;
            }
        }

        return $attributes;
    }
}
