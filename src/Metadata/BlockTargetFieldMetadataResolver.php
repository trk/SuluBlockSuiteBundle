<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

/**
 * Discovers mappable target content fields for any registered Sulu CMS block
 * by inspecting Sulu FormMetadata (including fields nested inside sections/tabs).
 */
final class BlockTargetFieldMetadataResolver
{
    private const EXCLUDED_FIELDS = [
        'props',
        'dynamic_source',
        'target_block',
        'reusable_snippet',
        'snippet',
    ];

    public function __construct(
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
    ) {
    }

    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    public function resolveTargetFields(string $blockType, string $locale = 'en'): array
    {
        return $this->resolveBlockMetadata($blockType, $locale)['fields'];
    }

    /**
     * @return array{
     *     multiple: bool,
     *     default_item_type: string|null,
     *     fields: list<array{name: string, label: string, type: string}>
     * }
     */
    public function resolveBlockMetadata(string $blockType, string $locale = 'en'): array
    {
        $cleanType = \strtolower(\trim($blockType));
        if ('' === $cleanType) {
            return [
                'multiple' => false,
                'default_item_type' => null,
                'fields' => [],
            ];
        }

        /** @var array<string, array{name: string, label: string, type: string}> $fieldsByName */
        $fieldsByName = [];
        $isMultiple = false;
        $defaultItemType = null;

        $formMetadata = $this->getBlockFormMetadata($cleanType, $locale);
        if (null !== $formMetadata) {
            foreach ($this->flattenFieldItems($formMetadata->getItems()) as $item) {
                $fieldName = $item->getName();
                $fieldType = $item->getType();

                if (\str_starts_with($fieldName, 'props/')) {
                    continue;
                }

                if ('items' === $fieldName && 'block' === $fieldType) {
                    $isMultiple = true;
                    $defaultItemType = $item->getDefaultType();

                    foreach ($item->getTypes() as $typeKey => $childFormMetadata) {
                        if (null === $defaultItemType && \is_string($typeKey) && '' !== $typeKey) {
                            $defaultItemType = $typeKey;
                        }
                        foreach ($this->flattenFieldItems($childFormMetadata->getItems()) as $childItem) {
                            $childFieldName = $childItem->getName();
                            $childFieldType = $childItem->getType();
                            if (
                                \str_starts_with($childFieldName, 'props/')
                                || \in_array($childFieldName, self::EXCLUDED_FIELDS, true)
                                || 'block' === $childFieldType
                            ) {
                                continue;
                            }

                            $rawChildLabel = $childItem->getLabel($locale) ?? $childItem->getLabel('en');
                            $humanChildLabel = (null !== $rawChildLabel && '' !== \trim($rawChildLabel))
                                ? \trim($rawChildLabel)
                                : \ucwords(\str_replace(['_', '-'], ' ', $childFieldName));

                            $fieldsByName[$childFieldName] ??= [
                                'name' => $childFieldName,
                                'label' => $this->formatFieldLabel($humanChildLabel, $childFieldName),
                                'type' => $this->mapSuluFieldType($childFieldType),
                            ];
                        }
                    }

                    continue;
                }

                if (\in_array($fieldName, self::EXCLUDED_FIELDS, true) || 'block' === $fieldType) {
                    continue;
                }

                $rawLabel = $item->getLabel($locale) ?? $item->getLabel('en');
                $humanLabel = (null !== $rawLabel && '' !== \trim($rawLabel))
                    ? \trim($rawLabel)
                    : \ucwords(\str_replace(['_', '-'], ' ', $fieldName));

                $fieldsByName[$fieldName] = [
                    'name' => $fieldName,
                    'label' => $this->formatFieldLabel($humanLabel, $fieldName),
                    'type' => $this->mapSuluFieldType($fieldType),
                ];
            }
        }

        return [
            'multiple' => $isMultiple,
            'default_item_type' => $defaultItemType,
            'fields' => \array_values($fieldsByName),
        ];
    }

    /**
     * Collects FieldMetadata items exclusively from root fields and `content`/`media` sections
     * (including `__tabs -> content`), skipping `settings`, `style`, `layout`, `general`, `dynamic_content`, and `advanced`.
     *
     * @param array<ItemMetadata> $items
     *
     * @return list<FieldMetadata>
     */
    private function flattenFieldItems(array $items): array
    {
        if (isset($items['__tabs']) && $items['__tabs'] instanceof SectionMetadata) {
            $contentTab = $items['__tabs']->getItems()['content'] ?? null;

            return $contentTab instanceof SectionMetadata
                ? $this->flattenFieldItems($contentTab->getItems())
                : [];
        }

        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof FieldMetadata) {
                $fields[] = $item;
            } elseif ($item instanceof SectionMetadata && \in_array($item->getName(), ['content', 'media'], true)) {
                foreach ($this->flattenFieldItems($item->getItems()) as $nested) {
                    $fields[] = $nested;
                }
            }
        }

        return $fields;
    }

    private function getBlockFormMetadata(string $blockType, string $locale): ?FormMetadata
    {
        if (null === $this->metadataProviderRegistry) {
            return null;
        }

        try {
            $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
            $metadata = $provider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);
            if ($metadata instanceof TypedFormMetadata) {
                $forms = $metadata->getForms();
                if (isset($forms[$blockType])) {
                    return $forms[$blockType];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function formatFieldLabel(string $label, string $name): string
    {
        return '' !== \trim($label) ? \trim($label) : $name;
    }

    private function mapSuluFieldType(string $suluType): string
    {
        return match ($suluType) {
            'text_area', 'text_editor' => 'text',
            'single_media_selection', 'media_selection' => 'media',
            'link' => 'link',
            'single_icon_selection', 'icon' => 'icon',
            'checkbox' => 'boolean',
            'single_select', 'select' => 'select',
            'date', 'datetime', 'time' => 'date',
            'number' => 'number',
            default => 'string',
        };
    }
}
