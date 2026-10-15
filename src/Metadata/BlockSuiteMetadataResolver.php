<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\MetadataResolver\MetadataResolver;
use Sulu\Content\Application\PropertyResolver\PropertyResolverProviderInterface;

/**
 * Decorates Sulu's MetadataResolver so that:
 * 1. Sibling SectionMetadata tabs (e.g. settings, style, layout, general, dynamic_content, advanced)
 *    are flattened before resolving, preventing `nestContentViews()` from overwriting `props` across sections.
 * 2. Native XML `<param name="default_value" .../>` options on FieldMetadata are applied when a property
 *    value is not present in the persisted block data.
 */
final class BlockSuiteMetadataResolver extends MetadataResolver
{
    public function __construct(PropertyResolverProviderInterface $propertyResolverProvider)
    {
        parent::__construct($propertyResolverProvider);
    }

    /**
     * @param ItemMetadata[] $items
     * @param mixed[] $data
     *
     * @return array<string, ContentView>
     */
    public function resolveItems(array $items, array $data, string $locale): array
    {
        $flatItems = $this->flattenSectionItems($items);
        $hydratedData = $this->applyFieldDefaults($flatItems, $data);

        $resolved = parent::resolveItems($flatItems, $hydratedData, $locale);

        // Preserve any extra keys inside $hydratedData['props'] (e.g. from presets or custom programmatic props)
        if (isset($hydratedData['props']) && \is_array($hydratedData['props'])) {
            $existingPropsView = $resolved['props'] ?? null;
            $resolvedPropsContent = [];
            $resolvedPropsViewData = [];

            if ($existingPropsView instanceof ContentView) {
                $content = $existingPropsView->getContent();
                if (\is_array($content)) {
                    $resolvedPropsContent = $content;
                }
                $resolvedPropsViewData = $existingPropsView->getView();
            }

            $mergedPropsContent = \array_replace($hydratedData['props'], $resolvedPropsContent);
            foreach ($hydratedData['props'] as $propKey => $propVal) {
                if (
                    !\array_key_exists($propKey, $resolvedPropsContent)
                    || (null === $resolvedPropsContent[$propKey] && null !== $propVal)
                ) {
                    $mergedPropsContent[$propKey] = $propVal;
                }
            }

            $resolved['props'] = ContentView::create($mergedPropsContent, $resolvedPropsViewData);
        }

        return $resolved;
    }

    /**
     * @param ItemMetadata[] $items
     *
     * @return array<string, FieldMetadata>
     */
    private function flattenSectionItems(array $items): array
    {
        $flat = [];
        foreach ($items as $key => $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->flattenSectionItems($item->getItems()) as $subKey => $subItem) {
                    $flat[$subKey] = $subItem;
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $name = '' !== $item->getName() ? $item->getName() : (string) $key;
                $flat[$name] = $item;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, FieldMetadata> $flatItems
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function applyFieldDefaults(array $flatItems, array $data): array
    {
        foreach ($flatItems as $name => $field) {
            $currentValue = $this->readValueByPath($data, $name);
            if (null !== $currentValue) {
                continue;
            }

            $defaultOption = $field->findOption('default_value');
            if (null === $defaultOption) {
                continue;
            }

            $defaultValue = $this->normalizeDefaultValue($field->getType(), $defaultOption->getValue());
            if (null === $defaultValue) {
                continue;
            }

            $this->writeValueByPath($data, $name, $defaultValue);
        }

        return $data;
    }

    private function normalizeDefaultValue(string $fieldType, mixed $rawValue): mixed
    {
        if ('checkbox' === $fieldType && \is_string($rawValue)) {
            $lower = \strtolower(\trim($rawValue));
            if ('true' === $lower || '1' === $lower) {
                return true;
            }
            if ('false' === $lower || '0' === $lower) {
                return false;
            }
        }

        if ('number' === $fieldType && \is_string($rawValue) && \is_numeric($rawValue)) {
            return \str_contains($rawValue, '.') ? (float) $rawValue : (int) $rawValue;
        }

        return $rawValue;
    }

    /**
     * @param array<mixed> $data
     */
    private function readValueByPath(array $data, string $path): mixed
    {
        if (\array_key_exists($path, $data)) {
            return $data[$path];
        }

        if (!\str_contains($path, '/')) {
            return null;
        }

        $current = $data;
        foreach (\explode('/', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<mixed> &$data
     */
    private function writeValueByPath(array &$data, string $path, mixed $value): void
    {
        if (!\str_contains($path, '/') || \array_key_exists($path, $data)) {
            $data[$path] = $value;

            return;
        }

        $segments = \explode('/', $path);
        $lastIndex = \count($segments) - 1;
        $current = &$data;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $current[$segment] = $value;

                return;
            }

            if (!isset($current[$segment]) || !\is_array($current[$segment])) {
                $current[$segment] = [];
            }

            /** @var array<string, mixed> $next */
            $next = &$current[$segment];
            $current = &$next;
        }
    }
}
