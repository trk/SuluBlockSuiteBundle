<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

/**
 * Discovers mappable target content fields, section partitions, and inline option groups
 * for any registered Sulu CMS block by inspecting Sulu FormMetadata.
 */
final class BlockTargetFieldMetadataResolver
{
    public const RESERVED_OPTION_NAMES = [
        'values' => true,
        'default_values' => true,
        'displayOptions' => true,
        'types' => true,
        'template_type' => true,
        'resource_key' => true,
        'groups' => true,
        'group' => true,
        'excluded_types' => true,
        'excluded_templates' => true,
        'exclude' => true,
        'include_global' => true,
        'permission_filtered' => true,
        'auto_default' => true,
        'attributes' => true,
        'properties' => true,
        'request_parameters' => true,
        'resource_store_properties_to_request' => true,
    ];

    public const INLINE_OPTION_META_NAMES = [
        '_icon' => true,
        'icon' => true,
        '_default' => true,
        'default_value' => true,
        'default' => true,
        '_target' => true,
        'target' => true,
        '_title' => true,
        '_storage_key' => true,
    ];

    private const EXCLUDED_FIELDS = [
        'props',
        'dynamic_source',
        'is_dynamic_template',
        'is_template',
        'target_block',
        'reusable_snippet',
        'snippet',
    ];

    public const MAPPABLE_SUB_BLOCK_FIELDS = [
        'elements',
        'badges',
        'buttons',
        'specs',
        'features',
        'tags',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private array $fieldTypeMapCache = [];

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
     * Memoized map of target field name => normalized type (`string`, `text`, `media`, `link`, `form`, etc.).
     *
     * @return array<string, string>
     */
    public function resolveFieldTypeMap(string $blockType, string $locale = 'en'): array
    {
        $cleanType = \strtolower(\trim($blockType));
        if ('' === $cleanType) {
            return [];
        }

        $cacheKey = $cleanType . ':' . $locale;
        if (isset($this->fieldTypeMapCache[$cacheKey])) {
            return $this->fieldTypeMapCache[$cacheKey];
        }

        $types = [];
        foreach ($this->resolveTargetFields($cleanType, $locale) as $fieldMeta) {
            $types[$fieldMeta['name']] = $fieldMeta['type'];
        }

        return $this->fieldTypeMapCache[$cacheKey] = $types;
    }

    /**
     * Partitions a block FormMetadata into its `content` items, custom `settings` sections,
     * `advanced` section, and `dynamic_content` section.
     *
     * @return array{
     *     content: array<array-key, ItemMetadata>,
     *     settings: list<SectionMetadata>,
     *     advanced: ?SectionMetadata,
     *     dynamic_content: ?SectionMetadata
     * }
     */
    public static function partitionBlockSections(FormMetadata $form): array
    {
        $items = $form->getItems();
        if (isset($items[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME]) && $items[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME] instanceof SectionMetadata) {
            $tabItems = $items[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME]->getItems();
            $contentItems = isset($tabItems['content']) && $tabItems['content'] instanceof SectionMetadata
                ? $tabItems['content']->getItems()
                : [];
            $settingsSections = [];
            $advancedSection = null;
            $dynamicSection = null;

            foreach ($tabItems as $tabKey => $tabSection) {
                if (!$tabSection instanceof SectionMetadata || 'content' === $tabKey) {
                    continue;
                }
                if ('advanced' === $tabKey) {
                    $advancedSection = $tabSection;
                } elseif ('dynamic_content' === $tabKey) {
                    $dynamicSection = $tabSection;
                } else {
                    $settingsSections[] = $tabSection;
                }
            }

            return [
                'content' => $contentItems,
                'settings' => $settingsSections,
                'advanced' => $advancedSection,
                'dynamic_content' => $dynamicSection,
            ];
        }

        return [
            'content' => $items,
            'settings' => [],
            'advanced' => null,
            'dynamic_content' => null,
        ];
    }

    public static function isPopoverOptionCollection(OptionMetadata $option): bool
    {
        if (OptionMetadata::TYPE_COLLECTION !== $option->getType()) {
            return false;
        }

        $name = (string) $option->getName();
        if ('' === $name || isset(self::RESERVED_OPTION_NAMES[$name])) {
            return false;
        }

        $children = $option->getValue();
        if (!\is_array($children) || [] === $children) {
            return false;
        }

        $hasMetaKey = false;
        $hasSelectableItem = false;

        foreach ($children as $child) {
            $childName = (string) ($child->getName() ?? '');
            if (isset(self::INLINE_OPTION_META_NAMES[$childName])) {
                $hasMetaKey = true;

                continue;
            }

            if ('' !== $childName || \is_scalar($child->getValue())) {
                $hasSelectableItem = true;
            }
        }

        return $hasSelectableItem && ($hasMetaKey || !self::isBooleanFlagCollection($children));
    }

    /**
     * Extracts inline ArrowMenu `<param type="collection">` option groups from a FieldMetadata,
     * excluding reserved Sulu options and boolean-flag feature collections.
     *
     * @return list<array{
     *     name: string,
     *     storage_key: string,
     *     default: string|int|bool|null,
     *     values: list<array{value: string, label: string}>
     * }>
     */
    public static function extractFieldOptionGroups(FieldMetadata $field, string $locale = 'en', string $prefix = 'props/'): array
    {
        $groups = [];
        foreach ($field->getOptions() as $option) {
            if (!self::isPopoverOptionCollection($option)) {
                continue;
            }

            $optName = (string) $option->getName();
            $children = $option->getValue();
            if (!\is_array($children)) {
                continue;
            }

            $targetName = $optName;
            $storageKey = null;
            /** @var string|int|bool|null $default */
            $default = null;
            /** @var list<array{value: string, label: string}> $values */
            $values = [];

            foreach ($children as $child) {
                $childName = (string) ($child->getName() ?? '');
                if (isset(self::INLINE_OPTION_META_NAMES[$childName])) {
                    $val = $child->getValue();
                    if (('_target' === $childName || 'target' === $childName) && \is_string($val) && '' !== \trim($val)) {
                        $targetName = \trim($val);
                    } elseif ('_storage_key' === $childName && \is_string($val) && '' !== \trim($val)) {
                        $storageKey = \trim($val);
                    } elseif (\in_array($childName, ['_default', 'default_value', 'default'], true) && (\is_string($val) || \is_int($val) || \is_bool($val))) {
                        $default = $val;
                    }

                    continue;
                }

                $rawVal = $child->getValue();
                $optVal = '' !== $childName ? $childName : (\is_scalar($rawVal) ? (string) $rawVal : '');
                if ('' === $optVal) {
                    continue;
                }

                $label = \trim((string) ($child->getTitle($locale) ?? $child->getTitle('en') ?? ''));
                if ('' === $label) {
                    $label = \is_scalar($rawVal) && '' !== \trim((string) $rawVal) ? \trim((string) $rawVal) : $optVal;
                }

                $values[] = [
                    'value' => $optVal,
                    'label' => $label,
                ];
            }

            if ([] === $values) {
                continue;
            }

            $resolvedStorageKey = $storageKey ?? ('' === $prefix || \str_starts_with($targetName, $prefix) ? $targetName : $prefix . $targetName);
            $groups[] = [
                'name' => $optName,
                'storage_key' => $resolvedStorageKey,
                'default' => $default ?? $values[0]['value'],
                'values' => $values,
            ];
        }

        return $groups;
    }

    /**
     * @param array<array-key, OptionMetadata> $children
     */
    private static function isBooleanFlagCollection(array $children): bool
    {
        foreach ($children as $child) {
            $val = $child->getValue();
            if (!\is_bool($val) && 'true' !== $val && 'false' !== $val) {
                return false;
            }
        }

        return true;
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
                                || ('block' === $childFieldType && !\in_array($childFieldName, self::MAPPABLE_SUB_BLOCK_FIELDS, true))
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

                            if ('elements' === $childFieldName && 'block' === $childFieldType) {
                                $this->appendVirtualElementTargets($childItem, $locale, $fieldsByName);
                            }
                        }
                    }

                    continue;
                }

                if (
                    \in_array($fieldName, self::EXCLUDED_FIELDS, true)
                    || ('block' === $fieldType && !\in_array($fieldName, self::MAPPABLE_SUB_BLOCK_FIELDS, true))
                ) {
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

                if ('elements' === $fieldName && 'block' === $fieldType) {
                    $this->appendVirtualElementTargets($item, $locale, $fieldsByName);
                }
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

    /**
     * @param array<string, array{name: string, label: string, type: string}> $fieldsByName
     */
    private function appendVirtualElementTargets(FieldMetadata $elementsField, string $locale, array &$fieldsByName): void
    {
        $types = $elementsField->getTypes();
        if (isset($types['button'])) {
            $rawBtn = $types['button']->getTitle($locale);
            if ('' === \trim($rawBtn)) {
                $rawBtn = $types['button']->getTitle('en');
            }
            $btnLabel = '' !== \trim($rawBtn) ? $rawBtn : 'Buttons';
            $fieldsByName['buttons'] ??= [
                'name' => 'buttons',
                'label' => $this->formatFieldLabel($btnLabel, 'buttons'),
                'type' => 'collection',
            ];
        }
        if (isset($types['badge'])) {
            $rawBadge = $types['badge']->getTitle($locale);
            if ('' === \trim($rawBadge)) {
                $rawBadge = $types['badge']->getTitle('en');
            }
            $badgeLabel = '' !== \trim($rawBadge) ? $rawBadge : 'Badges';
            $fieldsByName['badges'] ??= [
                'name' => 'badges',
                'label' => $this->formatFieldLabel($badgeLabel, 'badges'),
                'type' => 'collection',
            ];
        }
    }

    private function formatFieldLabel(string $label, string $name): string
    {
        return '' !== \trim($label) ? \trim($label) : $name;
    }

    public static function normalizeFieldType(string $suluType): string
    {
        return match (\strtolower(\trim($suluType))) {
            'text_area', 'text_editor', 'text', 'html', 'richtext' => 'text',
            'single_media_selection', 'media_selection', 'media', 'image' => 'media',
            'link', 'url', 'page_link' => 'link',
            'single_icon_selection', 'icon' => 'icon',
            'single_form_selection', 'form' => 'form',
            'checkbox', 'bool', 'boolean' => 'boolean',
            'single_select', 'select', 'single_template_selection', 'template_selection', 'single_webspace_select', 'webspace_select' => 'select',
            'date', 'datetime', 'time' => 'date',
            'number', 'int', 'integer', 'float', 'double', 'price' => 'number',
            'block', 'collection', 'array', 'badges', 'buttons', 'elements', 'list' => 'collection',
            default => 'string',
        };
    }

    private function mapSuluFieldType(string $suluType): string
    {
        return self::normalizeFieldType($suluType);
    }
}
