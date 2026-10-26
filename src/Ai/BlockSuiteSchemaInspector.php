<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Ai;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

/**
 * Inspects registered Sulu Block Suite block schemas, inline field option groups,
 * and visual presets with progressive disclosure for AI and MCP consumers.
 */
final class BlockSuiteSchemaInspector
{
    private const TRANSLATABLE_PROPS_ALLOWLIST = [
        'dynamic_empty_message' => true,
        'dynamic_summary_text' => true,
        'dynamic_filtered_summary_text' => true,
        'aria_label' => true,
        'alt' => true,
    ];

    private const NON_TRANSLATABLE_CONTENT_FIELDS = [
        'type' => true,
        'props' => true,
        'snippet' => true,
        'reusable_snippet' => true,
        'target_block' => true,
        'is_dynamic_template' => true,
        'is_template' => true,
    ];

    public function __construct(
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
        private readonly ?BlockPresetProviderInterface $presetProvider = null,
    ) {
    }

    /**
     * Returns a compact summary of all registered global block types.
     *
     * @return list<array{
     *     type: string,
     *     title: string,
     *     content_fields: list<string>,
     *     field_option_keys: list<string>,
     *     settings_props: list<string>,
     *     preset_count: int,
     *     presets: list<string>
     * }>
     */
    public function listBlocks(string $locale = 'en'): array
    {
        $forms = $this->getBlockForms($locale);
        $catalog = [];

        foreach ($forms as $blockType => $form) {
            if ('default' === $blockType || '' === \trim($blockType)) {
                continue;
            }

            $sections = BlockTargetFieldMetadataResolver::partitionBlockSections($form);
            $contentFields = [];
            $fieldOptionKeys = [];

            foreach ($this->collectFieldsFromItems($sections['content']) as $field) {
                $name = $field->getName();
                if ('' === $name || 'props' === $name || \str_starts_with($name, 'props/')) {
                    continue;
                }
                $contentFields[] = $name;
                foreach (BlockTargetFieldMetadataResolver::extractFieldOptionGroups($field, $locale) as $group) {
                    $fieldOptionKeys[] = $group['storage_key'];
                }
            }

            $settingsProps = [];
            foreach ($sections['settings'] as $section) {
                foreach ($this->collectFieldsFromItems($section->getItems()) as $field) {
                    $name = $field->getName();
                    if ('' !== $name && 'props' !== $name) {
                        $settingsProps[] = \str_starts_with($name, 'props/') ? $name : 'props/' . $name;
                    }
                }
            }

            $presets = $this->presetProvider?->getPresets($blockType, $locale) ?? [];
            $presetIds = \array_map(
                static fn (array $preset): string => $preset['id'],
                $presets,
            );

            $catalog[] = [
                'type' => $blockType,
                'title' => $this->resolveFormTitle($form, $blockType, $locale),
                'content_fields' => \array_values(\array_unique($contentFields)),
                'field_option_keys' => \array_values(\array_unique($fieldOptionKeys)),
                'settings_props' => \array_values(\array_unique($settingsProps)),
                'preset_count' => \count($presetIds),
                'presets' => $presetIds,
            ];
        }

        return $catalog;
    }

    /**
     * Inspects a single block type with progressive disclosure:
     * returns `content_fields` (including inline option groups), `settings_props`, and `presets` by default,
     * and only includes `advanced_props` or `dynamic_props` when explicitly requested.
     *
     * @return array{
     *     type: string,
     *     title: string,
     *     content_fields: list<array<string, mixed>>,
     *     settings_props: list<array<string, mixed>>,
     *     presets: list<array{id: string, title: string, description: ?string, props: array<string, mixed>, values: array<string, mixed>}>,
     *     advanced_props?: list<array<string, mixed>>,
     *     dynamic_props?: list<array<string, mixed>>
     * }|null
     */
    public function inspectBlock(
        string $blockType,
        string $locale = 'en',
        bool $includeAdvanced = false,
        bool $includeDynamic = false,
    ): ?array {
        $cleanType = \trim($blockType);
        if ('' === $cleanType) {
            return null;
        }

        $forms = $this->getBlockForms($locale);
        $form = $forms[$cleanType] ?? null;
        if (null === $form) {
            return null;
        }

        $sections = BlockTargetFieldMetadataResolver::partitionBlockSections($form);
        $contentFields = [];

        foreach ($this->collectFieldsFromItems($sections['content']) as $field) {
            $name = $field->getName();
            if ('' === $name || 'props' === $name || \str_starts_with($name, 'props/')) {
                continue;
            }
            $contentFields[] = $this->describeField($field, $locale, includeOptionGroups: true);
        }

        $settingsProps = [];
        foreach ($sections['settings'] as $section) {
            foreach ($this->collectFieldsFromItems($section->getItems()) as $field) {
                $name = $field->getName();
                if ('' !== $name && 'props' !== $name) {
                    $settingsProps[] = $this->describeField($field, $locale, includeOptionGroups: false);
                }
            }
        }

        $result = [
            'type' => $cleanType,
            'title' => $this->resolveFormTitle($form, $cleanType, $locale),
            'content_fields' => $contentFields,
            'settings_props' => $settingsProps,
            'presets' => $this->listPresets($cleanType, $locale),
        ];

        if ($includeAdvanced && null !== $sections['advanced']) {
            $advancedProps = [];
            foreach ($this->collectFieldsFromItems($sections['advanced']->getItems()) as $field) {
                $advancedProps[] = $this->describeField($field, $locale, includeOptionGroups: false);
            }
            $result['advanced_props'] = $advancedProps;
        }

        if ($includeDynamic && null !== $sections['dynamic_content']) {
            $dynamicProps = [];
            foreach ($this->collectFieldsFromItems($sections['dynamic_content']->getItems()) as $field) {
                $dynamicProps[] = $this->describeField($field, $locale, includeOptionGroups: false);
            }
            $result['dynamic_props'] = $dynamicProps;
        }

        return $result;
    }

    /**
     * Lists visual presets for a block type, optionally filtered by a case-insensitive search query.
     *
     * @return list<array{
     *     id: string,
     *     title: string,
     *     description: ?string,
     *     props: array<string, mixed>,
     *     values: array<string, mixed>
     * }>
     */
    public function listPresets(string $blockType, string $locale = 'en', ?string $query = null): array
    {
        $cleanType = \trim($blockType);
        if ('' === $cleanType || null === $this->presetProvider) {
            return [];
        }

        $presets = $this->presetProvider->getPresets($cleanType, $locale);
        $needle = null !== $query ? \strtolower(\trim($query)) : '';
        $result = [];

        foreach ($presets as $preset) {
            if ('' !== $needle) {
                $haystack = \strtolower(
                    $preset['id'] . ' ' . $preset['title'] . ' ' . ($preset['description'] ?? ''),
                );
                if (!\str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $result[] = [
                'id' => $preset['id'],
                'title' => $preset['title'],
                'description' => $preset['description'] ?? null,
                'props' => $preset['props'],
                'values' => $preset['values'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Determines whether a block field name represents human-readable translatable content
     * rather than a technical prop (`props/id`, `props/class`, `props/custom_css`, `props/attributes`, etc.).
     */
    public function isTranslatableField(string $fieldName): bool
    {
        $clean = \trim($fieldName);
        if ('' === $clean || isset(self::NON_TRANSLATABLE_CONTENT_FIELDS[$clean])) {
            return false;
        }

        if (\str_starts_with($clean, 'props/')) {
            $subKey = \substr($clean, 6);

            return isset(self::TRANSLATABLE_PROPS_ALLOWLIST[$subKey]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeField(FieldMetadata $field, string $locale, bool $includeOptionGroups): array
    {
        $name = $field->getName();
        $label = $field->getLabel($locale) ?? $field->getLabel('en');
        $resolvedLabel = (null !== $label && '' !== \trim($label))
            ? \trim($label)
            : \ucwords(\str_replace(['_', '-', 'props/'], [' ', ' ', ''], $name));

        $desc = [
            'name' => $name,
            'label' => $resolvedLabel,
            'type' => $field->getType(),
            'required' => $field->isRequired(),
            'translatable' => $this->isTranslatableField($name),
        ];

        $defaultOpt = $field->findOption('default_value');
        if (null !== $defaultOpt && null !== $defaultOpt->getValue()) {
            $desc['default'] = $defaultOpt->getValue();
        }

        $selectValues = $this->extractSelectValues($field, $locale);
        if ([] !== $selectValues) {
            $desc['values'] = $selectValues;
        }

        if ($includeOptionGroups) {
            $optionGroups = BlockTargetFieldMetadataResolver::extractFieldOptionGroups($field, $locale);
            if ([] !== $optionGroups) {
                $desc['option_groups'] = $optionGroups;
            }
        }

        if ('block' === $field->getType() && [] !== $field->getTypes()) {
            $subTypes = [];
            foreach ($field->getTypes() as $subTypeKey => $subForm) {
                $subFields = [];
                foreach ($this->collectFieldsFromItems($subForm->getItems()) as $subField) {
                    $subFields[] = $this->describeField($subField, $locale, includeOptionGroups: true);
                }
                $subTypes[(string) $subTypeKey] = [
                    'title' => $this->resolveFormTitle($subForm, (string) $subTypeKey, $locale),
                    'fields' => $subFields,
                ];
            }
            $desc['sub_types'] = $subTypes;
        }

        return $desc;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function extractSelectValues(FieldMetadata $field, string $locale): array
    {
        $valuesOpt = $field->findOption('values');
        if (null === $valuesOpt || OptionMetadata::TYPE_COLLECTION !== $valuesOpt->getType()) {
            return [];
        }

        $children = $valuesOpt->getValue();
        if (!\is_array($children)) {
            return [];
        }

        $values = [];
        foreach ($children as $child) {
            $rawName = (string) ($child->getName() ?? '');
            $rawVal = $child->getValue();
            $val = '' !== $rawName ? $rawName : (\is_scalar($rawVal) ? (string) $rawVal : '');
            if ('' === $val) {
                continue;
            }
            $label = $child->getTitle($locale) ?? $child->getTitle('en');
            if (null === $label || '' === \trim($label)) {
                $label = \is_scalar($rawVal) && '' !== \trim((string) $rawVal) ? \trim((string) $rawVal) : $val;
            }
            $values[] = [
                'value' => $val,
                'label' => \trim($label),
            ];
        }

        return $values;
    }

    /**
     * @return array<string, FormMetadata>
     */
    private function getBlockForms(string $locale): array
    {
        if (null === $this->metadataProviderRegistry) {
            return [];
        }

        try {
            $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
            $metadata = $provider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);
            if ($metadata instanceof TypedFormMetadata) {
                /** @var array<string, FormMetadata> $forms */
                $forms = $metadata->getForms();

                return $forms;
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @param array<array-key, ItemMetadata> $items
     *
     * @return list<FieldMetadata>
     */
    private function collectFieldsFromItems(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof FieldMetadata) {
                if ('false' === $item->getVisibleCondition()) {
                    continue;
                }
                $fields[] = $item;
            } elseif ($item instanceof SectionMetadata) {
                foreach ($this->collectFieldsFromItems($item->getItems()) as $nested) {
                    $fields[] = $nested;
                }
            }
        }

        return $fields;
    }

    private function resolveFormTitle(FormMetadata $form, string $blockType, string $locale): string
    {
        $title = $form->getTitle($locale);
        if ('' === \trim($title)) {
            $title = $form->getTitle('en');
        }

        return '' !== \trim($title)
            ? \trim($title)
            : \ucwords(\str_replace(['_', '-'], ' ', $blockType));
    }
}
