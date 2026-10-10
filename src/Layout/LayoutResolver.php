<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

final class LayoutResolver implements LayoutResolverInterface
{
    public const DEFAULT_CONTAINER_CLASS = 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8';
    public const DEFAULT_PADDING_CLASS = 'py-12 md:py-16';

    public const DEFAULT_MAPPINGS = [
        'container_width' => [
            'full' => 'w-full',
            'boxed' => self::DEFAULT_CONTAINER_CLASS,
            'narrow' => 'max-w-4xl mx-auto px-4 sm:px-6',
        ],
        'content_padding' => [
            'none' => 'py-0',
            'compact' => 'py-6 md:py-8',
            'normal' => self::DEFAULT_PADDING_CLASS,
            'spacious' => 'py-16 md:py-24',
        ],
        'margin_top' => [
            'none' => 'mt-0',
            'xs' => 'mt-2',
            'sm' => 'mt-4',
            'md' => 'mt-6',
            'lg' => 'mt-10',
            'xl' => 'mt-16',
            'auto' => 'mt-auto',
        ],
        'margin_bottom' => [
            'none' => 'mb-0',
            'xs' => 'mb-2',
            'sm' => 'mb-4',
            'md' => 'mb-6',
            'lg' => 'mb-10',
            'xl' => 'mb-16',
            'auto' => 'mb-auto',
        ],
        'max_width' => [
            'small' => 'max-w-xl mx-auto',
            'medium' => 'max-w-3xl mx-auto',
            'large' => 'max-w-5xl mx-auto',
            'xlarge' => 'max-w-7xl mx-auto',
            '2xlarge' => 'max-w-(--breakpoint-2xl) mx-auto',
        ],
        'block_align' => [
            'left' => 'text-left',
            'center' => 'text-center mx-auto',
            'right' => 'text-right ml-auto',
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private array $mappings;

    /**
     * @param array<string, array<string, string>> $mappings
     */
    public function __construct(
        array $mappings = [],
    ) {
        $merged = self::DEFAULT_MAPPINGS;
        foreach ($mappings as $group => $groupMappings) {
            $merged[$group] = \array_merge($merged[$group] ?? [], $groupMappings);
        }
        $this->mappings = $merged;
    }

    public function resolveClasses(?array $layoutData): array
    {
        $classes = [
            'container' => $this->resolveContainerClass($layoutData),
            'padding' => $this->resolvePaddingClass($layoutData),
        ];

        $sidebar = isset($layoutData['sidebar']) && \is_scalar($layoutData['sidebar'])
            ? (string) $layoutData['sidebar']
            : 'none';
        $classes['sidebar'] = $sidebar;

        $headerVariant = isset($layoutData['header_variant']) && \is_scalar($layoutData['header_variant'])
            ? (string) $layoutData['header_variant']
            : 'solid';
        $classes['header_variant'] = $headerVariant;

        // Dynamically resolve any other configured mapping groups
        foreach ($this->mappings as $group => $options) {
            if (!\array_key_exists($group, $classes)) {
                $val = isset($layoutData[$group]) && \is_scalar($layoutData[$group])
                    ? (string) $layoutData[$group]
                    : null;
                $classes[$group] = $this->resolveClass($group, $val);
            }
        }

        return $classes;
    }

    public function resolveClass(string $group, ?string $value, string $default = ''): string
    {
        if (null === $value || '' === $value) {
            return $this->mappings[$group]['default'] ?? $default;
        }

        return $this->mappings[$group][$value]
            ?? $this->mappings[$group]['default']
            ?? $default;
    }

    public function resolveContainerClass(?array $layoutData): string
    {
        $key = isset($layoutData['container_width']) && \is_scalar($layoutData['container_width'])
            ? (string) $layoutData['container_width']
            : 'boxed';

        return $this->mappings['container_width'][$key]
            ?? $this->mappings['container_width']['boxed']
            ?? $this->mappings['container_width']['default']
            ?? self::DEFAULT_CONTAINER_CLASS;
    }

    public function resolvePaddingClass(?array $layoutData): string
    {
        $key = isset($layoutData['content_padding']) && \is_scalar($layoutData['content_padding'])
            ? (string) $layoutData['content_padding']
            : 'normal';

        return $this->mappings['content_padding'][$key]
            ?? $this->mappings['content_padding']['normal']
            ?? $this->mappings['content_padding']['default']
            ?? self::DEFAULT_PADDING_CLASS;
    }

    public function resolveWrapperClasses(?array $props, string $extraClass = ''): string
    {
        $classes = [];
        if ('' !== $extraClass) {
            $classes[] = $extraClass;
        }

        if (null === $props) {
            return \trim($extraClass);
        }

        $mt = isset($props['margin_top']) && \is_scalar($props['margin_top']) ? (string) $props['margin_top'] : null;
        $mtClass = $this->resolveClass('margin_top', $mt);
        if ('' !== $mtClass) {
            $classes[] = $mtClass;
        }

        $mb = isset($props['margin_bottom']) && \is_scalar($props['margin_bottom']) ? (string) $props['margin_bottom'] : null;
        $mbClass = $this->resolveClass('margin_bottom', $mb);
        if ('' !== $mbClass) {
            $classes[] = $mbClass;
        }

        $mw = isset($props['max_width']) && \is_scalar($props['max_width']) ? (string) $props['max_width'] : null;
        $mwClass = $this->resolveClass('max_width', $mw);
        if ('' !== $mwClass) {
            $classes[] = $mwClass;
        }

        $align = isset($props['block_align']) && \is_scalar($props['block_align']) ? (string) $props['block_align'] : null;
        $alignClass = $this->resolveClass('block_align', $align);
        if ('' !== $alignClass) {
            $classes[] = $alignClass;
        }

        $customClass = isset($props['custom_class']) && \is_scalar($props['custom_class']) ? \trim((string) $props['custom_class']) : '';
        if ('' !== $customClass) {
            $classes[] = $customClass;
        }

        return \trim(\implode(' ', \array_filter($classes)));
    }

    public function resolveAttributes(?array $layoutData): array
    {
        if (null === $layoutData) {
            return [];
        }

        $attributes = [];
        foreach ($layoutData as $key => $value) {
            if (\is_scalar($value) && '' !== (string) $value && !\in_array($key, ['blocks', 'title', 'id', 'uuid', 'description'], true)) {
                $attrName = 'data-layout-' . \str_replace('_', '-', (string) $key);
                $attributes[$attrName] = (string) $value;
            }
        }

        return $attributes;
    }
}
