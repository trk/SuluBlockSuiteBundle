<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\ConfigurableDynamicContentSourceProviderInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydratorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceRegistry;

/**
 * Deep module that populates compile-time Dynamic Content options (`dynamic_source`, `dynamic_mapping`,
 * `dynamic_condition_field`, and multi-item pagination/sort/filter `visibleCondition` expressions)
 * and injects `is_dynamic_template` into multi-item sub-block types.
 */
final class DynamicSectionFormBuilder
{
    private const EXCLUDED_TARGET_FIELDS = [
        'props' => true,
        'dynamic_source' => true,
        'is_dynamic_template' => true,
        'target_block' => true,
        'reusable_snippet' => true,
        'snippet' => true,
    ];

    public function __construct(
        private readonly ?DynamicContentHydratorInterface $dynamicContentHydrator = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Injects compile-time options into `dynamic_source`, `dynamic_mapping`, and `dynamic_condition_field`.
     */
    public function populateDynamicSectionOptions(
        SectionMetadata $dynamicSection,
        SectionMetadata $contentSection,
        string $locale,
    ): void {
        $items = $dynamicSection->getItems();
        $isMultipleBlock = $this->isMultipleBlock($contentSection);
        $registry = $this->dynamicContentHydrator instanceof DynamicContentHydrator
            ? $this->dynamicContentHydrator->getRegistry()
            : new DynamicContentSourceRegistry(translator: $this->translator);

        // 1. Populate dynamic_source options
        $sourceField = $items['dynamic_source'] ?? null;
        if ($sourceField instanceof FieldMetadata) {
            $sourceOptions = $registry->getSourceDescriptors($locale);
            $values = [];
            foreach ($sourceOptions as $src) {
                if ('none' !== $src['key'] && $src['multi_item'] !== $isMultipleBlock) {
                    continue;
                }
                $opt = new OptionMetadata();
                $opt->setName($src['key']);
                $opt->setValue($src['key']);
                $opt->setTitle($src['label'], $locale);
                $values[] = $opt;
            }
            $valuesCollection = new OptionMetadata();
            $valuesCollection->setName('values');
            $valuesCollection->setType(OptionMetadata::TYPE_COLLECTION);
            $valuesCollection->setValue($values);
            $sourceField->addOption($valuesCollection);
        }

        // 2. Discover target fields for this block and source fields from registry
        $targetFields = $this->discoverBlockTargetFields($contentSection, $locale);
        $sourceFields = $this->discoverAllSourceFields($registry, $locale);

        // 3. Populate dynamic_mapping -> field_map -> target_field and source_field
        $mappingBlock = $items['dynamic_mapping'] ?? null;
        if ($mappingBlock instanceof FieldMetadata) {
            $fieldMapType = $mappingBlock->getTypes()['field_map'] ?? null;
            if ($fieldMapType instanceof FormMetadata) {
                $mapItems = $fieldMapType->getItems();
                $targetSelect = $mapItems['target_field'] ?? null;
                if ($targetSelect instanceof FieldMetadata) {
                    $targetOpts = [];
                    foreach ($targetFields as $tf) {
                        $opt = new OptionMetadata();
                        $opt->setName($tf['name']);
                        $opt->setValue($tf['name']);
                        $opt->setTitle($tf['label'], $locale);
                        $targetOpts[] = $opt;
                    }
                    $targetCollection = new OptionMetadata();
                    $targetCollection->setName('values');
                    $targetCollection->setType(OptionMetadata::TYPE_COLLECTION);
                    $targetCollection->setValue($targetOpts);
                    $targetSelect->addOption($targetCollection);

                    if (isset($targetFields[0])) {
                        $defaultTarget = new OptionMetadata();
                        $defaultTarget->setName('default_value');
                        $defaultTarget->setValue($targetFields[0]['name']);
                        $targetSelect->addOption($defaultTarget);
                    }
                }

                $sourceSelect = $mapItems['source_field'] ?? null;
                if ($sourceSelect instanceof FieldMetadata) {
                    $sourceOpts = [];
                    foreach ($sourceFields as $sf) {
                        $opt = new OptionMetadata();
                        $opt->setName($sf['name']);
                        $opt->setValue($sf['name']);
                        $opt->setTitle($sf['label'], $locale);
                        $sourceOpts[] = $opt;
                    }
                    $sourceCollection = new OptionMetadata();
                    $sourceCollection->setName('values');
                    $sourceCollection->setType(OptionMetadata::TYPE_COLLECTION);
                    $sourceCollection->setValue($sourceOpts);
                    $sourceSelect->addOption($sourceCollection);

                    if (isset($sourceFields[0])) {
                        $defaultSource = new OptionMetadata();
                        $defaultSource->setName('default_value');
                        $defaultSource->setValue($sourceFields[0]['name']);
                        $sourceSelect->addOption($defaultSource);
                    }
                }
            }
        }

        // 4. Populate dynamic_condition_field options
        $conditionField = $items['dynamic_condition_field'] ?? null;
        if ($conditionField instanceof FieldMetadata) {
            $condOpts = [];
            foreach ($sourceFields as $sf) {
                $opt = new OptionMetadata();
                $opt->setName($sf['name']);
                $opt->setValue($sf['name']);
                $opt->setTitle($sf['label'], $locale);
                $condOpts[] = $opt;
            }
            $condCollection = new OptionMetadata();
            $condCollection->setName('values');
            $condCollection->setType(OptionMetadata::TYPE_COLLECTION);
            $condCollection->setValue($condOpts);
            $conditionField->addOption($condCollection);
        }

        // 5. Dynamically extend visibleCondition on pagination/sort/filter fields for registered multi-item sources
        $descriptors = $registry->getSourceDescriptors($locale);
        $limitSourceKeys = [];
        $sortSourceKeys = [];
        $templateFilterSourceKeys = [];
        $tagFilterSourceKeys = [];
        $categoryFilterSourceKeys = [];

        foreach ($descriptors as $desc) {
            if (!$desc['multi_item'] || 'none' === $desc['key']) {
                continue;
            }

            $key = $desc['key'];
            $fieldNames = \array_column($desc['config_fields'], 'name');
            $limitSourceKeys[] = $key;

            if (\in_array('sort_by', $fieldNames, true) || \in_array($key, ['page_selection', 'page_children', 'sibling_pages', 'custom_query', 'sulu_articles'], true)) {
                $sortSourceKeys[] = $key;
            }
            if (\in_array('template_keys', $fieldNames, true) || \in_array($key, ['page_children', 'sibling_pages', 'custom_query', 'sulu_articles'], true)) {
                $templateFilterSourceKeys[] = $key;
            }
            if (\in_array('tag_names', $fieldNames, true) || \in_array($key, ['page_children', 'sibling_pages', 'custom_query', 'sulu_articles'], true)) {
                $tagFilterSourceKeys[] = $key;
            }
            if (\in_array('category_keys', $fieldNames, true) || \in_array($key, ['page_children', 'sibling_pages', 'custom_query', 'sulu_articles'], true)) {
                $categoryFilterSourceKeys[] = $key;
            }
        }

        $buildSourceVisibleCondition = static function (array $keys): ?string {
            $clauses = [];
            foreach ($keys as $k) {
                if (\is_string($k) && '' !== $k) {
                    $clauses[$k] = "__parent.dynamic_source == '{$k}'";
                }
            }
            if ([] === $clauses) {
                return null;
            }

            return \implode(' or ', \array_values($clauses));
        };

        $limitVisibleCondition = $buildSourceVisibleCondition($limitSourceKeys);

        $conditionMap = [
            'dynamic_limit' => $limitVisibleCondition,
            'dynamic_offset' => $limitVisibleCondition,
            'dynamic_pagination' => $limitVisibleCondition,
            'dynamic_per_page' => null !== $limitVisibleCondition
                ? "({$limitVisibleCondition}) and (__parent.dynamic_pagination == 'numbers' or __parent.dynamic_pagination == 'prev_next')"
                : null,
            'dynamic_show_summary' => $limitVisibleCondition,
            'dynamic_summary_text' => null !== $limitVisibleCondition
                ? "({$limitVisibleCondition}) and (__parent.dynamic_show_summary == 'top' or __parent.dynamic_show_summary == 'bottom' or __parent.dynamic_show_summary == 'both')"
                : null,
            'dynamic_filtered_summary_text' => null !== $limitVisibleCondition
                ? "({$limitVisibleCondition}) and (__parent.dynamic_show_summary == 'top' or __parent.dynamic_show_summary == 'bottom' or __parent.dynamic_show_summary == 'both')"
                : null,
            'dynamic_sort_by' => $buildSourceVisibleCondition($sortSourceKeys),
            'dynamic_sort_order' => $buildSourceVisibleCondition($sortSourceKeys),
            'dynamic_template_keys' => $buildSourceVisibleCondition($templateFilterSourceKeys),
            'dynamic_tag_names' => $buildSourceVisibleCondition($tagFilterSourceKeys),
            'dynamic_category_keys' => $buildSourceVisibleCondition($categoryFilterSourceKeys),
        ];

        foreach ($conditionMap as $fieldName => $conditionExpr) {
            if (null === $conditionExpr) {
                continue;
            }
            $fieldMeta = $items[$fieldName] ?? null;
            if ($fieldMeta instanceof FieldMetadata) {
                $fieldMeta->setVisibleCondition($conditionExpr);
            }
        }
    }

    /**
     * Injects the `is_dynamic_template` checkbox into every sub-item type of `<block name="items">`
     * so editors can mark specific sub-items as dynamic content templates while keeping unmarked
     * sibling sub-items rendered as static items.
     */
    public function injectDynamicTemplateFieldIntoSubItems(SectionMetadata $contentSection, string $locale): void
    {
        foreach ($contentSection->getItems() as $item) {
            if (!$item instanceof FieldMetadata || 'items' !== $item->getName() || 'block' !== $this->getFieldType($item)) {
                continue;
            }

            foreach ($item->getTypes() as $subTypeForm) {
                $templateField = $this->createDynamicTemplateFieldMetadata($locale);
                $subItems = $subTypeForm->getItems();

                if (isset($subItems[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME]) && $subItems[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME] instanceof SectionMetadata) {
                    $tabsItems = $subItems[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME]->getItems();
                    $contentTab = $tabsItems['content'] ?? null;
                    if ($contentTab instanceof SectionMetadata) {
                        if (!isset($contentTab->getItems()['is_dynamic_template'])) {
                            $subItems[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME]->addItem(
                                $this->prependFieldToSection($contentTab, $templateField),
                            );
                        }

                        continue;
                    }
                }

                if (isset($subItems['content']) && $subItems['content'] instanceof SectionMetadata) {
                    if (!isset($subItems['content']->getItems()['is_dynamic_template'])) {
                        $subItems['content'] = $this->prependFieldToSection($subItems['content'], $templateField);
                        $subTypeForm->setItems($subItems);
                    }

                    continue;
                }

                if (!isset($subItems['is_dynamic_template'])) {
                    $subTypeForm->setItems(['is_dynamic_template' => $templateField, ...$subItems]);
                }
            }
        }
    }

    private function isMultipleBlock(SectionMetadata $contentSection): bool
    {
        foreach ($contentSection->getItems() as $item) {
            if ($item instanceof FieldMetadata && 'items' === $item->getName() && 'block' === $this->getFieldType($item)) {
                return true;
            }
        }

        return false;
    }

    private function prependFieldToSection(SectionMetadata $section, FieldMetadata $firstField): SectionMetadata
    {
        $cloned = new SectionMetadata($section->getName());
        $cloned->setLabels($section->getLabels());
        $cloned->setDescriptions($section->getDescriptions());
        $cloned->setColSpan($section->getColSpan());
        $cloned->setDisabledCondition($section->getDisabledCondition());
        $cloned->setVisibleCondition($section->getVisibleCondition());
        $cloned->addItem($firstField);

        foreach ($section->getItems() as $existingItem) {
            $cloned->addItem($existingItem);
        }

        return $cloned;
    }

    private function createDynamicTemplateFieldMetadata(string $locale): FieldMetadata
    {
        $field = new FieldMetadata('is_dynamic_template');
        $field->setType('checkbox');
        $field->setColSpan(12);
        $field->setRequired(false);
        $field->setMultilingual(false);
        $field->setLabel(
            $this->translate('sulu_block_suite.dynamic_is_template', $locale, 'Use as Dynamic Content Template'),
            $locale,
        );
        $field->setDescription(
            $this->translate(
                'sulu_block_suite.dynamic_is_template_info',
                $locale,
                'Mark this item as the visual template for dynamic content items at this position. Unchecked items are rendered as normal static items.',
            ),
            $locale,
        );
        $field->setVisibleCondition(
            "__parent.__parent.dynamic_source != null and __parent.__parent.dynamic_source != 'none' and __parent.__parent.dynamic_source != ''",
        );

        $defaultOption = new OptionMetadata();
        $defaultOption->setName('default_value');
        $defaultOption->setType(OptionMetadata::TYPE_STRING);
        $defaultOption->setValue(false);
        $field->addOption($defaultOption);

        return $field;
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    private function discoverBlockTargetFields(SectionMetadata $contentSection, string $locale): array
    {
        /** @var array<string, array{name: string, label: string}> $fieldsByName */
        $fieldsByName = [];

        foreach (BlockTargetFieldMetadataResolver::collectContentFields($contentSection->getItems()) as $field) {
            $fieldName = $field->getName();
            $fieldType = $this->getFieldType($field);

            if ('items' === $fieldName && 'block' === $fieldType) {
                foreach ($field->getTypes() as $childForm) {
                    foreach (BlockTargetFieldMetadataResolver::collectContentFields($childForm->getItems()) as $childField) {
                        $childName = $childField->getName();
                        $childType = $this->getFieldType($childField);
                        if (
                            isset(self::EXCLUDED_TARGET_FIELDS[$childName])
                            || \str_starts_with($childName, 'props/')
                            || 'false' === $childField->getVisibleCondition()
                            || ('block' === $childType && !\in_array($childName, BlockTargetFieldMetadataResolver::MAPPABLE_SUB_BLOCK_FIELDS, true))
                        ) {
                            continue;
                        }
                        $label = $childField->getLabel($locale)
                            ?? AbstractDynamicContentSourceProvider::translateSourceFieldLabel($childName, $locale, $this->translator);
                        $fieldsByName[$childName] ??= [
                            'name' => $childName,
                            'label' => $label,
                        ];

                        if ('elements' === $childName && 'block' === $childType) {
                            $this->appendVirtualElementTargetFields($childField, $locale, $fieldsByName);
                        }
                    }
                }

                continue;
            }

            if (
                isset(self::EXCLUDED_TARGET_FIELDS[$fieldName])
                || \str_starts_with($fieldName, 'props/')
                || 'false' === $field->getVisibleCondition()
                || ('block' === $fieldType && !\in_array($fieldName, BlockTargetFieldMetadataResolver::MAPPABLE_SUB_BLOCK_FIELDS, true))
            ) {
                continue;
            }

            $label = $field->getLabel($locale)
                ?? AbstractDynamicContentSourceProvider::translateSourceFieldLabel($fieldName, $locale, $this->translator);
            $fieldsByName[$fieldName] = [
                'name' => $fieldName,
                'label' => $label,
            ];

            if ('elements' === $fieldName && 'block' === $fieldType) {
                $this->appendVirtualElementTargetFields($field, $locale, $fieldsByName);
            }
        }

        return \array_values($fieldsByName);
    }

    /**
     * @param array<string, array{name: string, label: string}> $fieldsByName
     */
    private function appendVirtualElementTargetFields(FieldMetadata $elementsField, string $locale, array &$fieldsByName): void
    {
        $types = $elementsField->getTypes();
        if (isset($types['button'])) {
            $rawBtn = $types['button']->getTitle($locale);
            if ('' === \trim($rawBtn)) {
                $rawBtn = $types['button']->getTitle('en');
            }
            $btnLabel = '' !== \trim($rawBtn)
                ? \trim($rawBtn)
                : AbstractDynamicContentSourceProvider::translateSourceFieldLabel('buttons', $locale, $this->translator);
            $fieldsByName['buttons'] ??= [
                'name' => 'buttons',
                'label' => $btnLabel,
            ];
        }
        if (isset($types['badge'])) {
            $rawBadge = $types['badge']->getTitle($locale);
            if ('' === \trim($rawBadge)) {
                $rawBadge = $types['badge']->getTitle('en');
            }
            $badgeLabel = '' !== \trim($rawBadge)
                ? \trim($rawBadge)
                : AbstractDynamicContentSourceProvider::translateSourceFieldLabel('badges', $locale, $this->translator);
            $fieldsByName['badges'] ??= [
                'name' => 'badges',
                'label' => $badgeLabel,
            ];
        }
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    private function discoverAllSourceFields(DynamicContentSourceRegistry $registry, string $locale): array
    {
        /** @var array<string, array{name: string, label: string}> $fieldsByName */
        $fieldsByName = [];

        foreach ($registry->getProviders() as $provider) {
            $sourceFields = $provider instanceof ConfigurableDynamicContentSourceProviderInterface
                ? $provider->resolveSourceFields($locale)
                : $provider->getSourceFields($locale);
            foreach ($sourceFields as $field) {
                $name = $field['name'];
                $fieldsByName[$name] ??= [
                    'name' => $name,
                    'label' => $field['label'],
                ];
            }
        }

        return \array_values($fieldsByName);
    }

    private function translate(string $key, string $locale, string $fallback): string
    {
        return AbstractDynamicContentSourceProvider::translateKey($key, $locale, $fallback, $this->translator);
    }

    private function getFieldType(FieldMetadata $field): ?string
    {
        try {
            return $field->getType();
        } catch (\Error) {
            return null;
        }
    }
}
