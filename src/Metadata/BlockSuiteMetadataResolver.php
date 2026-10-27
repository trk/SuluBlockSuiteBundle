<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\MetadataResolver\MetadataResolver;
use Sulu\Content\Application\PropertyResolver\PropertyResolverProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

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

        $contentItems = [];
        $propsItems = [];
        foreach ($flatItems as $name => $field) {
            if (\str_starts_with($name, 'props/')) {
                $propsItems[$name] = $field;
            } else {
                $contentItems[$name] = $field;
            }
        }

        $resolved = parent::resolveItems($contentItems, $hydratedData, $locale);

        if ([] !== $propsItems || (isset($hydratedData['props']) && \is_array($hydratedData['props']))) {
            /** @var array<string, mixed> $mergedPropsContent */
            $mergedPropsContent = isset($hydratedData['props']) && \is_array($hydratedData['props'])
                ? SuluDataNormalizer::unwrapContentViews($hydratedData['props'])
                : [];
            /** @var array<string, mixed> $mergedPropsView */
            $mergedPropsView = [];

            if ([] !== $propsItems) {
                $resolvedProps = parent::resolveItems($propsItems, $hydratedData, $locale);
                if (isset($resolvedProps['props'])) {
                    $resolvedPropsContent = SuluDataNormalizer::unwrapContentViews($resolvedProps['props']->getContent());
                    if (\is_array($resolvedPropsContent)) {
                        /** @var array<string, mixed> $resolvedPropsContent */
                        $mergedPropsContent = \array_replace_recursive($mergedPropsContent, $resolvedPropsContent);
                    }
                    /** @var array<string, mixed> $mergedPropsView */
                    $mergedPropsView = $resolvedProps['props']->getView();
                } else {
                    foreach ($propsItems as $propPath => $propField) {
                        $subPath = \substr($propPath, 6);
                        if ('' === $subPath) {
                            continue;
                        }

                        if (isset($resolvedProps[$propPath])) {
                            $val = SuluDataNormalizer::unwrapContentViews($resolvedProps[$propPath]->getContent());
                            $viewVal = $resolvedProps[$propPath]->getView();
                            if ([] !== $viewVal) {
                                $this->writeValueByPath($mergedPropsView, $subPath, $viewVal);
                            }
                        } else {
                            $val = SuluDataNormalizer::unwrapContentViews($this->readValueByPath($hydratedData, $propPath));
                        }
                        $this->writeValueByPath($mergedPropsContent, $subPath, $val);
                    }
                }
            }

            $resolved['props'] = ContentView::create($mergedPropsContent, $mergedPropsView);
        }

        return $resolved;
    }

    /**
     * @param ItemMetadata[] $items
     *
     * @return array<string, FieldMetadata>
     */
    private function flattenSectionItems(array $items, bool $inSection = false): array
    {
        $flat = [];
        foreach ($items as $key => $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->flattenSectionItems($item->getItems(), true) as $subKey => $subItem) {
                    $flat[$subKey] = $subItem;
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                if (!$inSection && \is_string($key) && '' !== $key) {
                    $name = $key;
                } else {
                    $name = '' !== $item->getName() ? $item->getName() : (string) $key;
                }

                // Skip synthetic collapsed-preview 'props' marker field
                if ('props' === $name && 'preset_selection' === $item->getType() && 'false' === $item->getVisibleCondition()) {
                    continue;
                }

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
            $this->applyInlineOptionGroupDefaults($field, $data);

            $currentValue = $this->readValueByPath($data, $name);
            if (null !== $currentValue) {
                if ('block' === $field->getType() && \is_array($currentValue) && [] !== $currentValue) {
                    $types = $field->getTypes();
                    if ([] !== $types) {
                        $defaultTypeKey = $field->getDefaultType();
                        $updatedEntries = [];
                        foreach ($currentValue as $idx => $entry) {
                            if (\is_array($entry)) {
                                $entryType = isset($entry['type']) && \is_string($entry['type']) && '' !== $entry['type']
                                    ? $entry['type']
                                    : $defaultTypeKey;
                                $typeForm = (null !== $entryType && isset($types[$entryType]))
                                    ? $types[$entryType]
                                    : \reset($types);
                                $nestedFlat = $this->flattenSectionItems($typeForm->getItems());
                                $entry = $this->applyFieldDefaults($nestedFlat, $entry);
                                if (null !== $entryType && !isset($entry['type'])) {
                                    $entry['type'] = $entryType;
                                }
                            }
                            $updatedEntries[$idx] = $entry;
                        }
                        $this->writeValueByPath($data, $name, $updatedEntries);
                    }
                }

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

    /**
     * @param array<mixed> &$data
     */
    private function applyInlineOptionGroupDefaults(FieldMetadata $field, array &$data): void
    {
        foreach ($field->getOptions() as $option) {
            if (OptionMetadata::TYPE_COLLECTION !== $option->getType()) {
                continue;
            }

            $children = $option->getValue();
            if (!\is_array($children)) {
                continue;
            }

            $storageKey = null;
            $defaultValue = null;
            foreach ($children as $child) {
                $childName = (string) ($child->getName() ?? '');
                if ('_storage_key' === $childName && \is_string($child->getValue()) && '' !== \trim($child->getValue())) {
                    $storageKey = \trim($child->getValue());
                } elseif (\in_array($childName, ['_default', 'default_value', 'default'], true)) {
                    $rawDefault = $child->getValue();
                    if (\is_string($rawDefault) || \is_int($rawDefault) || \is_bool($rawDefault)) {
                        $defaultValue = $rawDefault;
                    }
                }
            }

            if (null === $storageKey || null === $defaultValue) {
                continue;
            }

            if (null === $this->readValueByPath($data, $storageKey)) {
                $this->writeValueByPath($data, $storageKey, $defaultValue);
            }
        }
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
