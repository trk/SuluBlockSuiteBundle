<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

/**
 * Deep module that discovers inline ArrowMenu option collections in `<params>`,
 * injects `_storage_key` and `_default` metadata for the Admin UI enhancer and runtime MetadataResolver,
 * and removes duplicate properties from non-content sections (e.g. `settings`).
 */
final class PopoverOptionGroupNormalizer
{
    /**
     * @param array<string, SectionMetadata> $nonContentSections
     * @param (callable(FormMetadata): bool)|null $shouldSkipInlineSubType
     */
    public function processInSection(
        SectionMetadata $contentSection,
        array &$nonContentSections,
        string $prefix,
        string $locale,
        ?callable $shouldSkipInlineSubType = null,
    ): void {
        $discoveredGroups = $this->collectFromItems(
            $contentSection->getItems(),
            $prefix,
            $locale,
            $shouldSkipInlineSubType,
        );
        if ([] === $discoveredGroups) {
            return;
        }

        $targetNamesToRemove = [];
        foreach ($discoveredGroups as $group) {
            $targetNamesToRemove[$group['target_name']] = true;
            $targetNamesToRemove[$group['storage_key']] = true;
        }

        foreach ($nonContentSections as $sectionKey => $section) {
            $nonContentSections[$sectionKey] = $this->removeFieldsFromSection($section, $targetNamesToRemove);
        }
    }

    /**
     * Processes inline ArrowMenu option groups on a non-tabbed inline sub-block `FormMetadata` (e.g. `items` sub-types).
     *
     * @param (callable(FormMetadata): bool)|null $shouldSkipInlineSubType
     */
    public function processInForm(
        FormMetadata $form,
        string $prefix,
        string $locale,
        ?callable $shouldSkipInlineSubType = null,
    ): void {
        $items = $form->getItems();
        if (isset($items[BlockSuiteFormMetadataVisitor::TABS_SECTION_NAME])) {
            return;
        }

        $this->collectFromItems($items, $prefix, $locale, $shouldSkipInlineSubType);
    }

    /**
     * @param array<array-key, ItemMetadata> $items
     * @param (callable(FormMetadata): bool)|null $shouldSkipInlineSubType
     *
     * @return list<array{
     *     target_name: string,
     *     storage_key: string,
     *     default_value: string|int|bool|null,
     *     value_options: list<array{value: string, label: string}>
     * }>
     */
    private function collectFromItems(
        array $items,
        string $prefix,
        string $locale,
        ?callable $shouldSkipInlineSubType = null,
    ): array {
        $groups = [];

        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->collectFromItems($item->getItems(), $prefix, $locale, $shouldSkipInlineSubType) as $subGroup) {
                    $groups[] = $subGroup;
                }

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            if ('false' === $item->getVisibleCondition()) {
                continue;
            }

            foreach ($item->getOptions() as $option) {
                if (!BlockTargetFieldMetadataResolver::isPopoverOptionCollection($option)) {
                    continue;
                }

                $normalized = $this->normalizePopoverOptionCollection($option, $prefix, $locale);
                if (null !== $normalized) {
                    $groups[] = $normalized;
                }
            }

            foreach ($item->getTypes() as $subTypeForm) {
                $skipSubType = null !== $shouldSkipInlineSubType && $shouldSkipInlineSubType($subTypeForm);
                if (!$subTypeForm->hasTag('sulu.global_block') && !$skipSubType) {
                    $this->processInForm($subTypeForm, '', $locale, $shouldSkipInlineSubType);
                }
            }
        }

        return $groups;
    }

    /**
     * @return array{
     *     target_name: string,
     *     storage_key: string,
     *     default_value: string|int|bool|null,
     *     value_options: list<array{value: string, label: string}>
     * }|null
     */
    private function normalizePopoverOptionCollection(
        OptionMetadata $option,
        string $prefix,
        string $locale,
    ): ?array {
        $children = $option->getValue();
        if (!\is_array($children)) {
            return null;
        }

        $groupName = (string) $option->getName();
        $targetName = $groupName;
        /** @var string|int|bool|null $defaultValue */
        $defaultValue = null;
        $hasExplicitDefault = false;
        /** @var list<array{value: string, label: string}> $valueOptions */
        $valueOptions = [];
        /** @var list<OptionMetadata> $normalizedChildren */
        $normalizedChildren = [];

        foreach ($children as $child) {
            $childName = (string) ($child->getName() ?? '');
            if ('_storage_key' === $childName) {
                continue;
            }

            if ('_target' === $childName || 'target' === $childName) {
                $rawTarget = $child->getValue();
                if (\is_string($rawTarget) && '' !== \trim($rawTarget)) {
                    $targetName = \trim($rawTarget);
                }
                $normalizedChildren[] = $child;

                continue;
            }

            if ('_default' === $childName || 'default_value' === $childName || 'default' === $childName) {
                $rawDefault = $child->getValue();
                if (\is_string($rawDefault) || \is_int($rawDefault) || \is_bool($rawDefault)) {
                    $defaultValue = $rawDefault;
                    $hasExplicitDefault = true;
                }
                $normalizedChildren[] = $child;

                continue;
            }

            if ('_icon' === $childName || 'icon' === $childName) {
                $normalizedChildren[] = $child;

                continue;
            }

            $rawVal = $child->getValue();
            $optValue = '' !== $childName
                ? $childName
                : (\is_scalar($rawVal) ? (string) $rawVal : '');

            if ('' === $optValue) {
                continue;
            }

            if ('' === $childName) {
                $child->setName($optValue);
            }

            $title = $child->getTitle($locale);
            if (null === $title || '' === \trim($title)) {
                $titles = $child->getTitles();
                $title = $titles['en'] ?? (\reset($titles) ?: null);
            }

            if (null !== $title && '' !== \trim($title)) {
                $optLabel = \trim($title);
            } elseif (\is_scalar($rawVal) && '' !== \trim((string) $rawVal)) {
                $optLabel = \trim((string) $rawVal);
                $child->setTitle($optLabel, $locale);
            } else {
                $optLabel = $optValue;
                $child->setTitle($optLabel, $locale);
            }

            $valueOptions[] = [
                'value' => $optValue,
                'label' => $optLabel,
            ];
            $normalizedChildren[] = $child;
        }

        if ([] === $valueOptions) {
            return null;
        }

        $defaultValue ??= $valueOptions[0]['value'];
        if (!$hasExplicitDefault) {
            $defaultOption = new OptionMetadata();
            $defaultOption->setName('_default');
            $defaultOption->setType(OptionMetadata::TYPE_STRING);
            $defaultOption->setValue($defaultValue);
            $normalizedChildren[] = $defaultOption;
        }

        $storageKey = ('' === $prefix || \str_starts_with($targetName, $prefix))
            ? $targetName
            : $prefix . $targetName;

        $storageKeyOption = new OptionMetadata();
        $storageKeyOption->setName('_storage_key');
        $storageKeyOption->setType(OptionMetadata::TYPE_STRING);
        $storageKeyOption->setValue($storageKey);
        $normalizedChildren[] = $storageKeyOption;

        $option->setValue($normalizedChildren);

        return [
            'target_name' => $targetName,
            'storage_key' => $storageKey,
            'default_value' => $defaultValue,
            'value_options' => $valueOptions,
        ];
    }

    /**
     * @param array<string, true> $fieldNamesToRemove
     */
    private function removeFieldsFromSection(SectionMetadata $section, array $fieldNamesToRemove): SectionMetadata
    {
        $clonedSection = new SectionMetadata($section->getName());
        $clonedSection->setLabels($section->getLabels());
        $clonedSection->setDescriptions($section->getDescriptions());
        $clonedSection->setColSpan($section->getColSpan());
        $clonedSection->setDisabledCondition($section->getDisabledCondition());
        $clonedSection->setVisibleCondition($section->getVisibleCondition());

        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $filteredSubSection = $this->removeFieldsFromSection($item, $fieldNamesToRemove);
                if ([] !== $filteredSubSection->getItems()) {
                    $clonedSection->addItem($filteredSubSection);
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                if (isset($fieldNamesToRemove[$item->getName()])) {
                    continue;
                }
                $clonedSection->addItem($item);
            }
        }

        return $clonedSection;
    }
}
