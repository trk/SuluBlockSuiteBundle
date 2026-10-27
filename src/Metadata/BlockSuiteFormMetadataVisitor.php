<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\Loader\FormXmlLoader;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SchemaMetadataProvider;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydratorInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

/**
 * Compile-time Sulu FormMetadataVisitor that:
 * 1. Wraps block sections (`content`, user-defined `settings`/`style`/`layout`/custom tabs, and shared
 *    `general`, `dynamic_content`, `advanced` tabs) into `<section name="__tabs">`.
 * 2. Automatically prefixes all non-`content` tab fields with `props/` so Sulu natively persists and resolves
 *    them under `$block['props']` without polluting root content properties.
 * 3. Injects compile-time options into `props/dynamic_source`, `props/dynamic_mapping`, and `props/preset`.
 */
final class BlockSuiteFormMetadataVisitor implements FormMetadataVisitorInterface, TypedFormMetadataVisitorInterface
{
    public const TABS_TAG = 'sulu_block_suite.tabs';
    public const TABS_SECTION_NAME = '__tabs';

    private const DEFAULT_PREVIEW_EXCLUDED_FIELDS = [
        'preset',
        'dynamic_source',
        'dynamic_mapping',
        'is_dynamic_template',
        'image_url',
        'video_url',
        'link_title',
    ];

    private const DEFAULT_PREVIEW_PRIMARY_TEXT_FIELDS = [
        'title',
        'text',
        'content',
        'description',
        'label',
    ];

    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $registeredBlocksCache = [];

    private bool $isLoadingRegisteredBlocks = false;

    private FormSelectFieldPopulator $selectFieldPopulator;

    private SharedXmlSectionLoader $sharedXmlSectionLoader;

    private PopoverOptionGroupNormalizer $popoverOptionGroupNormalizer;

    private DynamicSectionFormBuilder $dynamicSectionFormBuilder;

    /**
     * @var list<string>
     */
    private array $presetDirectories;

    /**
     * @var array<string, true>
     */
    private array $previewExcludedFieldMap;

    /**
     * @var array<string, true>
     */
    private array $previewPrimaryTextFieldMap;

    /**
     * @param string|array<array-key, string> $presetDirectory
     * @param list<string> $previewExcludedFields
     * @param list<string> $previewPrimaryTextFields
     */
    public function __construct(
        string|array $presetDirectory = [],
        private readonly ?string $projectDirectory = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
        ?FormXmlLoader $formXmlLoader = null,
        private readonly ?SchemaMetadataProvider $schemaMetadataProvider = null,
        ?DynamicContentHydratorInterface $dynamicContentHydrator = null,
        private readonly ?BlockPresetProviderInterface $presetProvider = null,
        array $previewExcludedFields = [],
        array $previewPrimaryTextFields = [],
        ?WebspaceManagerInterface $webspaceManager = null,
        bool $multiWebspaceEnabled = true,
        ?FormSelectFieldPopulator $selectFieldPopulator = null,
        ?SharedXmlSectionLoader $sharedXmlSectionLoader = null,
        ?PopoverOptionGroupNormalizer $popoverOptionGroupNormalizer = null,
        ?DynamicSectionFormBuilder $dynamicSectionFormBuilder = null,
    ) {
        $dirs = \is_array($presetDirectory) ? \array_values($presetDirectory) : [$presetDirectory];
        $this->presetDirectories = \array_values(\array_filter($dirs, static fn (string $dir): bool => '' !== \trim($dir)));
        $this->previewExcludedFieldMap = \array_fill_keys(
            \array_values(\array_unique([...self::DEFAULT_PREVIEW_EXCLUDED_FIELDS, ...$previewExcludedFields])),
            true,
        );
        $this->previewPrimaryTextFieldMap = \array_fill_keys(
            \array_values(\array_unique([...self::DEFAULT_PREVIEW_PRIMARY_TEXT_FIELDS, ...$previewPrimaryTextFields])),
            true,
        );
        $this->selectFieldPopulator = $selectFieldPopulator ?? new FormSelectFieldPopulator(
            projectDirectory: $this->projectDirectory,
            translator: $this->translator,
            metadataProviderRegistry: $this->metadataProviderRegistry,
            webspaceManager: $webspaceManager,
            multiWebspaceEnabled: $multiWebspaceEnabled,
        );
        $this->sharedXmlSectionLoader = $sharedXmlSectionLoader ?? new SharedXmlSectionLoader($formXmlLoader);
        $this->popoverOptionGroupNormalizer = $popoverOptionGroupNormalizer ?? new PopoverOptionGroupNormalizer();
        $this->dynamicSectionFormBuilder = $dynamicSectionFormBuilder ?? new DynamicSectionFormBuilder(
            dynamicContentHydrator: $dynamicContentHydrator,
            translator: $this->translator,
        );
    }

    /**
     * @param array<mixed> $metadataOptions
     */
    public function visitTypedFormMetadata(TypedFormMetadata $formMetadata, string $key, string $locale, array $metadataOptions = []): void
    {
        if ('block' !== $key) {
            $this->selectFieldPopulator->cacheTemplateDescriptors($formMetadata, $key, $locale);
        }

        if ('block' === $key) {
            $blockForms = [];
            foreach ($formMetadata->getForms() as $formKey => $form) {
                if (\is_string($formKey)) {
                    $this->enhanceBlockTabs($form, $locale, includeSharedTabs: true, namespaceProps: true, updateSchema: false);
                    $this->enhanceBlockPreviewTags($form);
                    $blockForms[$formKey] = $form;
                }
            }
            $sortedBlockForms = $this->sortBlockFormsAlphabetically($blockForms, $locale);
            foreach (\array_keys($blockForms) as $formKey) {
                $formMetadata->removeForm($formKey);
            }
            foreach ($sortedBlockForms as $formKey => $form) {
                $formMetadata->addForm($formKey, $form);
            }
            $this->registeredBlocksCache[$locale] = $sortedBlockForms;
        }

        foreach ($formMetadata->getForms() as $form) {
            $this->visitFormMetadata($form, $locale, $metadataOptions);
            if ('block' === $key && null !== $this->schemaMetadataProvider) {
                $form->setSchema($this->schemaMetadataProvider->getMetadata($form->getItems()));
            }
        }
    }

    /**
     * @param array<mixed> $metadataOptions
     */
    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        // 1. Recursively enhance inline block types with tabs
        $this->enhanceAutoBlocksInItems($formMetadata->getItems(), $formMetadata, $locale);

        // 2. Populate single_template_selection and template_selection options across all form items
        $webspaceHidden = $this->selectFieldPopulator->populateSelectFieldsInItems(
            $formMetadata->getItems(),
            $locale,
            fn (string $loc): array => $this->getRegisteredBlockForms($loc),
        );
        if ($webspaceHidden && null !== $this->schemaMetadataProvider) {
            $formMetadata->setSchema($this->schemaMetadataProvider->getMetadata($formMetadata->getItems()));
        }

        // 3. Enhance block preview tags for any block form (or container containing blocks)
        $this->enhanceBlockPreviewTags($formMetadata);

        $items = $formMetadata->getItems();
        if (
            !isset($items['target_block'], $items['block'])
            || !$items['target_block'] instanceof FieldMetadata
            || !$items['block'] instanceof FieldMetadata
        ) {
            return;
        }

        $targetBlockProperty = $items['target_block'];
        $blockProperty = $items['block'];

        /** @var list<string> $blockTypes */
        $blockTypes = [];
        $typeForms = $blockProperty->getTypes();
        foreach (\array_keys($typeForms) as $typeKey) {
            if (\is_string($typeKey) && '' !== $typeKey && 'default' !== $typeKey) {
                $blockTypes[] = $typeKey;
            }
        }

        if (0 === \count($blockTypes)) {
            return;
        }

        /** @var list<OptionMetadata> $options */
        $options = [];
        foreach ($blockTypes as $blockType) {
            $typeTitles = isset($typeForms[$blockType]) ? $typeForms[$blockType]->getTitles() : [];
            $option = new OptionMetadata();
            $option->setName($blockType);
            $option->setValue($blockType);
            $title = $typeTitles[$locale] ?? '';
            $option->setTitle('' !== $title ? $title : $this->resolveBlockTitle($blockType, $locale), $locale);

            $options[] = $option;
        }

        \usort(
            $options,
            static fn (OptionMetadata $a, OptionMetadata $b): int => \strnatcasecmp(
                $a->getTitle($locale) ?? (string) $a->getName(),
                $b->getTitle($locale) ?? (string) $b->getName(),
            ),
        );

        $valuesOption = new OptionMetadata();
        $valuesOption->setName('values');
        $valuesOption->setType(OptionMetadata::TYPE_COLLECTION);
        $valuesOption->setValue($options);

        $targetBlockProperty->addOption($valuesOption);

        if (null === $targetBlockProperty->findOption('default_value')) {
            $defaultOption = new OptionMetadata();
            $defaultOption->setName('default_value');
            $defaultOption->setValue($blockTypes[0]);
            $targetBlockProperty->addOption($defaultOption);
        }
    }

    /**
     * Wraps a block FormMetadata's sections into `<section name="__tabs">`, optionally namespaces all non-`content`
     * tab fields under `props/`, and appends shared suite tabs (`dynamic_content`, `advanced`).
     */
    public function enhanceBlockTabs(
        FormMetadata $blockForm,
        string $locale,
        bool $includeSharedTabs = true,
        bool $namespaceProps = true,
        bool $updateSchema = true,
    ): void {
        $blockKey = $blockForm->getKey();
        if ('' === $blockKey || 'default' === $blockKey) {
            return;
        }

        $existingItems = $blockForm->getItems();
        if (isset($existingItems[self::TABS_SECTION_NAME])) {
            return;
        }

        $tabsTag = $blockForm->findTag(self::TABS_TAG);
        $enabledAttr = $tabsTag?->getAttribute('enabled');
        if (null !== $tabsTag && ('false' === $enabledAttr || false === $enabledAttr)) {
            return;
        }

        $excludeTabsAttr = $tabsTag?->getAttribute('exclude');
        $excludedTabs = \is_string($excludeTabsAttr) && '' !== \trim($excludeTabsAttr)
            ? \array_fill_keys(\array_map('trim', \explode(',', $excludeTabsAttr)), true)
            : [];

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel($this->resolveContentSectionLabel($locale), $locale);

        /** @var array<string, SectionMetadata> $nonContentSections */
        $nonContentSections = [];

        foreach ($existingItems as $item) {
            if ($item instanceof SectionMetadata) {
                $sectionName = $item->getName();
                if ('content' === $sectionName) {
                    $label = $item->getLabel($locale);
                    if (null !== $label && '' !== $label) {
                        $contentSection->setLabel($label, $locale);
                    }
                    foreach ($item->getItems() as $childItem) {
                        $contentSection->addItem($childItem);
                    }
                } elseif ('media' === $sectionName) {
                    // Keep <section name="media"> inside the Content tab so its fields remain flat at the block root
                    $contentSection->addItem($item);
                } else {
                    $nonContentSections[$sectionName] = $item;
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $contentSection->addItem($item);
            }
        }

        // Normalize default_value types on content fields (e.g. checkbox string "true" -> bool true)
        $this->sharedXmlSectionLoader->normalizeSectionFieldDefaults($contentSection);
        $this->selectFieldPopulator->populateNavigationContextFields($contentSection, $locale);
        $registeredFormsCallback = fn (string $loc): array => $this->getRegisteredBlockForms($loc);
        $this->selectFieldPopulator->populateSelectFieldsInItems($contentSection->getItems(), $locale, $registeredFormsCallback);
        foreach ($nonContentSections as $nonContentSection) {
            $this->selectFieldPopulator->populateSelectFieldsInItems($nonContentSection->getItems(), $locale, $registeredFormsCallback);
        }

        $prefix = $namespaceProps ? 'props/' : '';
        $this->popoverOptionGroupNormalizer->processInSection(
            $contentSection,
            $nonContentSections,
            $prefix,
            $locale,
            fn (FormMetadata $subForm): bool => $this->shouldEnhanceInlineTypeWithTabs($subForm),
        );

        if ($includeSharedTabs) {
            if (!isset($excludedTabs['advanced'])) {
                $advancedSection = $this->sharedXmlSectionLoader->loadSharedSection('block_suite_advanced', 'advanced');
                if (null !== $advancedSection) {
                    $this->populatePresetSectionOptions($advancedSection, $blockKey, $locale);
                    if (isset($nonContentSections['advanced'])) {
                        $nonContentSections['advanced'] = $this->sharedXmlSectionLoader->mergeSections($nonContentSections['advanced'], $advancedSection);
                    } else {
                        $nonContentSections['advanced'] = $advancedSection;
                    }
                }
            }

            if (!isset($excludedTabs['dynamic_content'])) {
                $this->dynamicSectionFormBuilder->injectDynamicTemplateFieldIntoSubItems($contentSection, $locale);
                $dynamicSection = $this->sharedXmlSectionLoader->loadSharedSection('block_suite_dynamic', 'dynamic_content');
                if (null !== $dynamicSection) {
                    $this->dynamicSectionFormBuilder->populateDynamicSectionOptions($dynamicSection, $contentSection, $locale);
                    if (isset($nonContentSections['dynamic_content'])) {
                        $nonContentSections['dynamic_content'] = $this->sharedXmlSectionLoader->mergeSections($nonContentSections['dynamic_content'], $dynamicSection);
                    } else {
                        $nonContentSections['dynamic_content'] = $dynamicSection;
                    }
                }
            }
        }

        if (isset($nonContentSections['dynamic_content'])) {
            $dynamicSectionItem = $nonContentSections['dynamic_content'];
            unset($nonContentSections['dynamic_content']);
            $nonContentSections['dynamic_content'] = $dynamicSectionItem;
        }

        $tabsSection = new SectionMetadata(self::TABS_SECTION_NAME);
        $tabsSection->setLabel(self::TABS_SECTION_NAME, $locale);
        $tabCount = 0;

        if ([] !== $contentSection->getItems()) {
            $tabsSection->addItem($contentSection);
            ++$tabCount;
        }

        foreach ($nonContentSections as $section) {
            if ([] === $section->getItems()) {
                continue;
            }

            $namespacedSection = $this->sharedXmlSectionLoader->namespaceSectionFields($section, $prefix);
            if ([] === $namespacedSection->getItems()) {
                continue;
            }

            $tabsSection->addItem($namespacedSection);
            ++$tabCount;
        }

        if (0 === $tabCount || (1 === $tabCount && [] !== $contentSection->getItems())) {
            if (isset($existingItems['content']) && $existingItems['content'] instanceof SectionMetadata) {
                $blockForm->setItems(['content' => $contentSection]);
            } else {
                $blockForm->setItems($contentSection->getItems());
            }
            if ($updateSchema && null !== $this->schemaMetadataProvider) {
                $blockForm->setSchema($this->schemaMetadataProvider->getMetadata($blockForm->getItems()));
            }

            return;
        }

        $blockForm->setItems([self::TABS_SECTION_NAME => $tabsSection]);

        if ($updateSchema && null !== $this->schemaMetadataProvider) {
            $blockForm->setSchema($this->schemaMetadataProvider->getMetadata($blockForm->getItems()));
        }
    }

    private function resolveContentSectionLabel(string $locale): string
    {
        return $this->translate('sulu_block_suite.tab_content', $locale, 'Content');
    }

    private function translate(string $key, string $locale, string $fallback): string
    {
        return AbstractDynamicContentSourceProvider::translateKey($key, $locale, $fallback, $this->translator);
    }

    private function populatePresetSectionOptions(SectionMetadata $advancedSection, string $blockKey, string $locale = 'en'): void
    {
        $presetField = $advancedSection->getItems()['preset'] ?? null;
        if (!$presetField instanceof FieldMetadata) {
            return;
        }

        $typeOption = new OptionMetadata();
        $typeOption->setName('component_type');
        $typeOption->setValue($blockKey);
        $presetField->addOption($typeOption);

        $previewPresetsJson = null;
        if (null !== $this->presetProvider) {
            $presets = $this->presetProvider->getPresets($blockKey, $locale);
            $presetsJson = \json_encode($presets, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
            $presetsJsonOption = new OptionMetadata();
            $presetsJsonOption->setName('presets_json');
            $presetsJsonOption->setValue($presetsJson);
            $presetField->addOption($presetsJsonOption);

            $previewPresets = \array_map(
                static fn (array $preset): array => [
                    'id' => $preset['id'],
                    'title' => $preset['title'],
                ],
                $presets,
            );
            $previewPresetsJson = \json_encode($previewPresets, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        }

        // Add a hidden 'props' preview field so Sulu's FieldBlocks (which looks up value[schemaKey] directly)
        // invokes PresetSelectionBlockPreviewTransformer with the block's `value.props` object when collapsed.
        if (!isset($advancedSection->getItems()['props'])) {
            $propsPreviewField = new FieldMetadata('props');
            $propsPreviewField->setType('preset_selection');
            $propsPreviewField->setVisibleCondition('false');
            $propsPreviewField->setRequired(false);
            $propsPreviewField->setMultilingual(false);

            $previewTag = new TagMetadata();
            $previewTag->setName('sulu.block_preview');
            $previewTag->setPriority(4096);
            $propsPreviewField->addTag($previewTag);

            $skipMapperTag = new TagMetadata();
            $skipMapperTag->setName('sulu_content.skip_template_data_mapper');
            $propsPreviewField->addTag($skipMapperTag);

            $previewTypeOption = new OptionMetadata();
            $previewTypeOption->setName('component_type');
            $previewTypeOption->setValue($blockKey);
            $propsPreviewField->addOption($previewTypeOption);

            if (null !== $previewPresetsJson) {
                $previewPresetsOption = new OptionMetadata();
                $previewPresetsOption->setName('presets_json');
                $previewPresetsOption->setValue($previewPresetsJson);
                $propsPreviewField->addOption($previewPresetsOption);
            }

            $advancedSection->addItem($propsPreviewField);
        }
    }

    /**
     * Recursively traverses form items (sections, inline block types, and block fields)
     * to enhance inline sub-types with tabs.
     *
     * @param array<array-key, ItemMetadata> $items
     * @param array<int, true> $visitedFormIds
     */
    private function enhanceAutoBlocksInItems(
        array $items,
        FormMetadata $rootForm,
        string $locale,
        array $visitedFormIds = [],
    ): void {
        $visitedFormIds[\spl_object_id($rootForm)] = true;

        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $this->enhanceAutoBlocksInItems($item->getItems(), $rootForm, $locale, $visitedFormIds);

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            $fieldTabsTag = $this->findFieldTag($item, self::TABS_TAG);
            $existingTypes = $item->getTypes();
            foreach ($existingTypes as $typeKey => $typeForm) {
                $formObjectId = \spl_object_id($typeForm);
                if (\is_string($typeKey) && !$typeForm->hasTag('sulu.global_block') && !isset($visitedFormIds[$formObjectId])) {
                    if (null !== $fieldTabsTag && !$typeForm->hasTag(self::TABS_TAG)) {
                        $typeForm->addTag(clone $fieldTabsTag);
                    }

                    if ($this->shouldEnhanceInlineTypeWithTabs($typeForm)) {
                        $tabsTag = $typeForm->findTag(self::TABS_TAG);
                        $sharedAttr = $tabsTag?->getAttribute('shared');
                        $includeShared = 'true' === $sharedAttr || true === $sharedAttr;
                        $namespaceAttr = $tabsTag?->getAttribute('namespace_props');
                        $namespaceProps = null !== $namespaceAttr
                            ? ('true' === $namespaceAttr || true === $namespaceAttr)
                            : $includeShared;
                        $this->enhanceBlockTabs(
                            $typeForm,
                            $locale,
                            includeSharedTabs: $includeShared,
                            namespaceProps: $namespaceProps,
                            updateSchema: false,
                        );
                    } else {
                        $this->popoverOptionGroupNormalizer->processInForm(
                            $typeForm,
                            '',
                            $locale,
                            fn (FormMetadata $subForm): bool => $this->shouldEnhanceInlineTypeWithTabs($subForm),
                        );
                    }

                    $nextVisited = $visitedFormIds;
                    $nextVisited[$formObjectId] = true;

                    $this->enhanceAutoBlocksInItems(
                        $typeForm->getItems(),
                        $rootForm,
                        $locale,
                        $nextVisited,
                    );
                }
            }
        }
    }

    private function shouldEnhanceInlineTypeWithTabs(FormMetadata $typeForm): bool
    {
        if ($typeForm->hasTag(self::TABS_TAG)) {
            $enabledAttr = $typeForm->findTag(self::TABS_TAG)?->getAttribute('enabled');

            return 'false' !== $enabledAttr && false !== $enabledAttr;
        }

        foreach ($typeForm->getItems() as $item) {
            if ($item instanceof SectionMetadata && !\in_array($item->getName(), ['content', 'media', self::TABS_SECTION_NAME], true)) {
                return true;
            }
        }

        return false;
    }

    private function findFieldTag(FieldMetadata $field, string $tagName): ?TagMetadata
    {
        foreach ($field->getTags() as $tag) {
            if ($tag->getName() === $tagName) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Ensures block forms (and nested inline block types) expose structured preview tags
     * in the priority order: Media (2048) -> Data/Text (1024..512) -> Child Blocks (256).
     */
    private function enhanceBlockPreviewTags(FormMetadata $formMetadata): void
    {
        $allFields = $this->collectFlatFieldMetadata($formMetadata->getItems());
        if ([] === $allFields) {
            return;
        }

        $tabsSection = $formMetadata->getItems()[self::TABS_SECTION_NAME] ?? null;
        $isTabbedBlock = $tabsSection instanceof SectionMetadata;
        foreach ($allFields as $field) {
            foreach ($field->getTypes() as $subTypeForm) {
                $this->enhanceBlockPreviewTags($subTypeForm);
            }
        }

        if (!$isTabbedBlock && !$formMetadata->hasTag('sulu.global_block')) {
            return;
        }

        $contentSection = $isTabbedBlock ? ($tabsSection->getItems()['content'] ?? null) : null;
        $fields = $contentSection instanceof SectionMetadata
            ? $this->collectFlatFieldMetadata($contentSection->getItems())
            : $allFields;
        if ([] === $fields) {
            return;
        }

        $hasExplicitPreviewTag = false;
        foreach ($fields as $field) {
            if (null !== $this->findFieldTag($field, 'sulu.block_preview')) {
                $hasExplicitPreviewTag = true;
                break;
            }
        }

        if ($hasExplicitPreviewTag) {
            foreach ($fields as $field) {
                if (null !== $this->findFieldTag($field, 'sulu.block_preview')) {
                    continue;
                }
                if (\str_starts_with($field->getName(), 'props/')) {
                    continue;
                }
                $fieldType = $this->getFieldType($field);
                if ('block' === $fieldType) {
                    $this->addBlockPreviewTag($field, 50);
                }
            }

            return;
        }

        foreach ($fields as $field) {
            $name = $field->getName();
            if (
                isset($this->previewExcludedFieldMap[$name])
                || \str_starts_with($name, 'props/')
                || 'false' === $field->getVisibleCondition()
            ) {
                continue;
            }

            $type = $this->getFieldType($field);
            $priority = match ($type) {
                'single_reusable_block_selection' => 2048,
                'single_media_selection', 'media_selection' => 'image' === $name ? 2048 : 1900,
                'text_line' => isset($this->previewPrimaryTextFieldMap[$name]) ? 1024 : 768,
                'date', 'datetime', 'time', 'email', 'phone', 'url', 'color', 'smart_content' => 900,
                'text_editor', 'text_area' => 512,
                'block' => 256,
                default => null,
            };

            if (null !== $priority) {
                $this->addBlockPreviewTag($field, $priority);
            }
        }
    }

    private function getFieldType(FieldMetadata $field): ?string
    {
        try {
            return $field->getType();
        } catch (\TypeError) {
            return null;
        }
    }

    /**
     * @param array<array-key, ItemMetadata> $items
     *
     * @return list<FieldMetadata>
     */
    private function collectFlatFieldMetadata(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->collectFlatFieldMetadata($item->getItems()) as $subField) {
                    $fields[] = $subField;
                }
            } elseif ($item instanceof FieldMetadata) {
                $fields[] = $item;
            }
        }

        return $fields;
    }

    private function addBlockPreviewTag(FieldMetadata $field, int $priority): void
    {
        if (null !== $this->findFieldTag($field, 'sulu.block_preview')) {
            return;
        }

        $tag = new TagMetadata();
        $tag->setName('sulu.block_preview');
        $tag->setPriority($priority);
        $field->addTag($tag);
    }

    /**
     * @return list<string>
     */
    public function discoverAvailableBlockTypes(?string $locale = null): array
    {
        $resolvedLocale = $locale ?? 'en';
        $types = [];
        $excluded = ['reusable', 'default', ''];

        $blockForms = $this->getRegisteredBlockForms($resolvedLocale);
        foreach (\array_keys($blockForms) as $blockKey) {
            if (!\in_array($blockKey, $excluded, true)) {
                $types[$blockKey] = true;
            }
        }

        foreach ($this->presetDirectories as $dir) {
            if (\is_dir($dir)) {
                $files = \glob(\rtrim($dir, '/') . '/*.yaml');
                if (false !== $files) {
                    foreach ($files as $file) {
                        $basename = \basename($file, '.yaml');
                        if (!\in_array($basename, $excluded, true)) {
                            $types[$basename] = true;
                        }
                    }
                }
            }
        }

        if (null !== $this->projectDirectory) {
            $blocksDir = \rtrim($this->projectDirectory, '/') . '/config/templates/blocks';
            if (\is_dir($blocksDir)) {
                $files = \glob($blocksDir . '/*.xml');
                if (false !== $files) {
                    foreach ($files as $file) {
                        $basename = \basename($file, '.xml');
                        if (!\in_array($basename, $excluded, true)) {
                            $types[$basename] = true;
                        }
                    }
                }
            }
        }

        $typeList = \array_keys($types);
        \sort($typeList);

        return $typeList;
    }

    public function resolveBlockTitle(string $blockType, string $locale): string
    {
        $blockForms = $this->getRegisteredBlockForms($locale);
        if (isset($blockForms[$blockType])) {
            $titles = $blockForms[$blockType]->getTitles();
            if (isset($titles[$locale]) && '' !== $titles[$locale]) {
                return $titles[$locale];
            }
            if (isset($titles['en']) && '' !== $titles['en']) {
                return $titles['en'];
            }
            $firstTitle = \reset($titles);
            if (\is_string($firstTitle) && '' !== $firstTitle) {
                return $firstTitle;
            }
        }

        if (null !== $this->projectDirectory) {
            $xmlFile = \rtrim($this->projectDirectory, '/') . '/config/templates/blocks/' . $blockType . '.xml';
            if (\is_file($xmlFile)) {
                $xml = @\simplexml_load_file($xmlFile);
                if (false !== $xml && isset($xml->meta)) {
                    $enTitle = null;
                    foreach ($xml->meta->title as $t) {
                        $attributes = $t->attributes();
                        if (isset($attributes['lang'])) {
                            $lang = (string) $attributes['lang'];
                            if ($lang === $locale) {
                                return (string) $t;
                            }
                            if ('en' === $lang) {
                                $enTitle = (string) $t;
                            }
                        }
                    }
                    if (null !== $enTitle) {
                        return $enTitle;
                    }
                }
            }
        }

        return \ucfirst(\str_replace(['_', '-'], ' ', $blockType));
    }

    /**
     * @return array<string, FormMetadata>
     */
    private function getRegisteredBlockForms(string $locale): array
    {
        if (isset($this->registeredBlocksCache[$locale])) {
            return $this->registeredBlocksCache[$locale];
        }

        if (null === $this->metadataProviderRegistry) {
            return $this->registeredBlocksCache[$locale] = [];
        }

        if ($this->isLoadingRegisteredBlocks) {
            return [];
        }

        $this->isLoadingRegisteredBlocks = true;

        try {
            $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
            $metadata = $provider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);
            if ($metadata instanceof TypedFormMetadata) {
                $blockForms = [];
                foreach ($metadata->getForms() as $key => $form) {
                    if (\is_string($key)) {
                        $this->enhanceBlockTabs($form, $locale, includeSharedTabs: true);
                        $this->enhanceBlockPreviewTags($form);
                        $blockForms[$key] = $form;
                    }
                }

                return $this->registeredBlocksCache[$locale] = $this->sortBlockFormsAlphabetically($blockForms, $locale);
            }
        } catch (\Throwable) {
            // Gracefully ignore early initialization or cache warmup
        } finally {
            $this->isLoadingRegisteredBlocks = false;
        }

        return $this->registeredBlocksCache[$locale] = [];
    }

    /**
     * Sorts a map of block `FormMetadata` instances alphabetically by their localized title
     * (falling back to English title and block key).
     *
     * @param array<string, FormMetadata> $forms
     *
     * @return array<string, FormMetadata>
     */
    private function sortBlockFormsAlphabetically(array $forms, string $locale): array
    {
        \uasort($forms, static function (FormMetadata $a, FormMetadata $b) use ($locale): int {
            $titlesA = $a->getTitles();
            $titlesB = $b->getTitles();

            $labelA = $titlesA[$locale] ?? $titlesA['en'] ?? $a->getKey();
            $labelB = $titlesB[$locale] ?? $titlesB['en'] ?? $b->getKey();

            $cmp = \strnatcasecmp((string) $labelA, (string) $labelB);

            return 0 !== $cmp ? $cmp : \strnatcasecmp($a->getKey(), $b->getKey());
        });

        return $forms;
    }
}
