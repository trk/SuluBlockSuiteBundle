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
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydratorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceRegistry;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

/**
 * Compile-time Sulu FormMetadataVisitor that:
 * 1. Wraps block sections (`content`, user-defined `settings`/`style`/`layout`/custom tabs, and shared
 *    `general`, `dynamic_content`, `advanced` tabs) into `<section name="__tabs">`.
 * 2. Automatically prefixes all non-`content` tab fields with `props/` so Sulu natively persists and resolves
 *    them under `$block['props']` without polluting root content properties.
 * 3. Injects compile-time options into `props/dynamic_source`, `props/dynamic_mapping`, and `props/preset`.
 * 4. Populates `<block>` fields carrying `<tag name="sulu_block_suite.auto_blocks" .../>`.
 */
final class BlockSuiteFormMetadataVisitor implements FormMetadataVisitorInterface, TypedFormMetadataVisitorInterface
{
    public const AUTO_BLOCKS_TAG = 'sulu_block_suite.auto_blocks';
    public const TABS_TAG = 'sulu_block_suite.tabs';
    public const TABS_SECTION_NAME = '__tabs';

    private const NON_DYNAMIC_BLOCKS = [
        'reusable' => true,
        'main_content' => true,
        'divider' => true,
        'spacer' => true,
        'row_item' => true,
        'column_item' => true,
    ];

    private const EXCLUDED_TARGET_FIELDS = [
        'props' => true,
        'dynamic_source' => true,
        'target_block' => true,
        'reusable_snippet' => true,
        'snippet' => true,
        'rows' => true,
        'items' => true,
        'columns' => true,
        'blocks' => true,
    ];

    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $registeredBlocksCache = [];

    private bool $isLoadingRegisteredBlocks = false;

    /**
     * @var array<string, SectionMetadata>
     */
    private array $sharedSectionPrototypes = [];

    /**
     * @var list<string>
     */
    private array $presetDirectories;

    /**
     * @param string|array<array-key, string> $presetDirectory
     */
    public function __construct(
        string|array $presetDirectory = [],
        private readonly ?string $projectDirectory = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
        private readonly ?FormXmlLoader $formXmlLoader = null,
        private readonly ?SchemaMetadataProvider $schemaMetadataProvider = null,
        private readonly ?DynamicContentHydratorInterface $dynamicContentHydrator = null,
        private readonly ?BlockPresetProviderInterface $presetProvider = null,
    ) {
        $dirs = \is_array($presetDirectory) ? \array_values($presetDirectory) : [$presetDirectory];
        $this->presetDirectories = \array_values(\array_filter($dirs, static fn (string $dir): bool => '' !== \trim($dir)));
    }

    /**
     * @param array<mixed> $metadataOptions
     */
    public function visitTypedFormMetadata(TypedFormMetadata $formMetadata, string $key, string $locale, array $metadataOptions = []): void
    {
        if ('block' === $key) {
            $blockForms = [];
            foreach ($formMetadata->getForms() as $formKey => $form) {
                if (\is_string($formKey)) {
                    $this->enhanceBlockTabs($form, $locale, includeSharedTabs: true);
                    $this->enhanceBlockPreviewTags($form);
                    $blockForms[$formKey] = $form;
                }
            }
            $this->registeredBlocksCache[$locale] = $blockForms;
        }

        foreach ($formMetadata->getForms() as $form) {
            $this->visitFormMetadata($form, $locale, $metadataOptions);
        }
    }

    /**
     * @param array<mixed> $metadataOptions
     */
    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        $key = $formMetadata->getKey();

        // 1. Recursively enhance inline block types and <block> fields tagged with sulu_block_suite.auto_blocks
        $this->enhanceAutoBlocksInItems($formMetadata->getItems(), $formMetadata, $locale, [$key]);

        // 2. Enhance block preview tags for any block form (or container containing blocks)
        $this->enhanceBlockPreviewTags($formMetadata);

        // 3. Legacy/programmatic support for snippet forms constructed without tags
        if ('reusable_block' === $key) {
            $this->enhanceBlockTypes($formMetadata, $locale, 'blocks', ['reusable', 'main_content']);

            return;
        }

        if ('page_layout' === $key) {
            $this->enhanceBlockTypes($formMetadata, $locale, 'blocks', []);

            return;
        }

        if ('block_preset' !== $key) {
            return;
        }

        $this->enhanceBlockTypes($formMetadata, $locale, 'block', ['reusable', 'main_content']);

        $items = $formMetadata->getItems();
        if (!isset($items['target_block']) || !$items['target_block'] instanceof FieldMetadata) {
            return;
        }

        /** @var FieldMetadata $targetBlockProperty */
        $targetBlockProperty = $items['target_block'];

        $blockTypes = $this->discoverAvailableBlockTypes($locale);
        if (0 === \count($blockTypes)) {
            return;
        }

        /** @var list<OptionMetadata> $options */
        $options = [];
        foreach ($blockTypes as $blockType) {
            $title = $this->resolveBlockTitle($blockType, $locale);

            $option = new OptionMetadata();
            $option->setName($blockType);
            $option->setValue($blockType);
            $option->setTitle($title, $locale);

            $options[] = $option;
        }

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
     * tab fields under `props/`, and appends shared suite tabs (`general`, `dynamic_content`, `advanced`).
     */
    public function enhanceBlockTabs(
        FormMetadata $blockForm,
        string $locale,
        bool $includeSharedTabs = true,
        bool $namespaceProps = true,
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
        $contentSection->setLabels([
            'en' => 'Content',
            'tr' => 'İçerik',
            'de' => 'Inhalt',
            'ru' => 'Контент',
            'es' => 'Contenido',
            'fr' => 'Contenu',
        ]);

        /** @var array<string, SectionMetadata> $nonContentSections */
        $nonContentSections = [];

        foreach ($existingItems as $item) {
            if ($item instanceof SectionMetadata) {
                $sectionName = $item->getName();
                if ('content' === $sectionName) {
                    if ([] !== $item->getLabels()) {
                        $contentSection->setLabels($item->getLabels());
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
        $this->normalizeSectionFieldDefaults($contentSection);

        if ($includeSharedTabs) {
            if (!isset($excludedTabs['general'])) {
                $generalSection = $this->loadSharedSection('block_suite_general', 'general');
                if (null !== $generalSection) {
                    if (isset($nonContentSections['general'])) {
                        $nonContentSections['general'] = $this->mergeSections($nonContentSections['general'], $generalSection);
                    } else {
                        $nonContentSections['general'] = $generalSection;
                    }
                }
            }

            if (!isset(self::NON_DYNAMIC_BLOCKS[$blockKey]) && !isset($excludedTabs['dynamic_content'])) {
                $dynamicSection = $this->loadSharedSection('block_suite_dynamic', 'dynamic_content');
                if (null !== $dynamicSection) {
                    $this->populateDynamicSectionOptions($dynamicSection, $blockForm, $contentSection, $locale);
                    if (isset($nonContentSections['dynamic_content'])) {
                        $nonContentSections['dynamic_content'] = $this->mergeSections($nonContentSections['dynamic_content'], $dynamicSection);
                    } else {
                        $nonContentSections['dynamic_content'] = $dynamicSection;
                    }
                }
            }

            if (!isset($excludedTabs['advanced'])) {
                $advancedSection = $this->loadSharedSection('block_suite_advanced', 'advanced');
                if (null !== $advancedSection) {
                    $this->populatePresetSectionOptions($advancedSection, $blockKey);
                    if (isset($nonContentSections['advanced'])) {
                        $nonContentSections['advanced'] = $this->mergeSections($nonContentSections['advanced'], $advancedSection);
                    } else {
                        $nonContentSections['advanced'] = $advancedSection;
                    }
                }
            }
        }

        $tabsSection = new SectionMetadata(self::TABS_SECTION_NAME);
        $tabsSection->setLabel(self::TABS_SECTION_NAME, $locale);
        $tabCount = 0;

        if ([] !== $contentSection->getItems()) {
            $tabsSection->addItem($contentSection);
            ++$tabCount;
        }

        $prefix = $namespaceProps ? 'props/' : '';
        foreach ($nonContentSections as $section) {
            if ([] === $section->getItems()) {
                continue;
            }

            $namespacedSection = $this->namespaceSectionFields($section, $prefix);
            if ([] === $namespacedSection->getItems()) {
                continue;
            }

            $tabsSection->addItem($namespacedSection);
            ++$tabCount;
        }

        if (0 === $tabCount) {
            return;
        }

        $blockForm->setItems([self::TABS_SECTION_NAME => $tabsSection]);

        if (null !== $this->schemaMetadataProvider) {
            $blockForm->setSchema($this->schemaMetadataProvider->getMetadata($blockForm->getItems()));
        }
    }

    /**
     * Recursively prefixes all FieldMetadata items inside a non-content section with `$prefix` (e.g. `props/`).
     */
    private function namespaceSectionFields(SectionMetadata $section, string $prefix): SectionMetadata
    {
        $clonedSection = new SectionMetadata($section->getName());
        $clonedSection->setLabels($section->getLabels());
        $clonedSection->setDescriptions($section->getDescriptions());
        $clonedSection->setColSpan($section->getColSpan());
        $clonedSection->setDisabledCondition($section->getDisabledCondition());
        $clonedSection->setVisibleCondition($section->getVisibleCondition());

        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $clonedSection->addItem($this->namespaceSectionFields($item, $prefix));

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $rawName = $item->getName();
                $namespacedName = ('' === $prefix || \str_starts_with($rawName, $prefix))
                    ? $rawName
                    : $prefix . $rawName;
                $clonedField = $this->cloneFieldMetadata($item, $namespacedName);
                $this->normalizeFieldDefaultOption($clonedField);
                $clonedSection->addItem($clonedField);
            }
        }

        return $clonedSection;
    }

    private function normalizeSectionFieldDefaults(SectionMetadata $section): void
    {
        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $this->normalizeSectionFieldDefaults($item);
            } elseif ($item instanceof FieldMetadata) {
                $this->normalizeFieldDefaultOption($item);
            }
        }
    }

    private function normalizeFieldDefaultOption(FieldMetadata $field): void
    {
        $defaultOption = $field->findOption('default_value');
        if (null === $defaultOption || OptionMetadata::TYPE_EXPRESSION === $defaultOption->getType()) {
            return;
        }

        $rawValue = $defaultOption->getValue();
        $fieldType = $this->getFieldType($field);

        if ('checkbox' === $fieldType) {
            if (\is_bool($rawValue)) {
                $defaultOption->setType(OptionMetadata::TYPE_STRING);

                return;
            }
            if (\is_string($rawValue)) {
                $lower = \strtolower(\trim($rawValue));
                if ('true' === $lower || '1' === $lower) {
                    $defaultOption->setValue(true);
                    $defaultOption->setType(OptionMetadata::TYPE_STRING);
                } elseif ('false' === $lower || '0' === $lower) {
                    $defaultOption->setValue(false);
                    $defaultOption->setType(OptionMetadata::TYPE_STRING);
                }
            }
        } elseif ('number' === $fieldType && \is_string($rawValue) && \is_numeric($rawValue)) {
            $defaultOption->setValue(\str_contains($rawValue, '.') ? $rawValue : (int) $rawValue);
            $defaultOption->setType(OptionMetadata::TYPE_STRING);
        }
    }

    private function cloneFieldMetadata(FieldMetadata $source, string $newName): FieldMetadata
    {
        $cloned = new FieldMetadata($newName);
        $type = $this->getFieldType($source);
        if (null !== $type) {
            $cloned->setType($type);
        }
        $cloned->setLabels($source->getLabels());
        $cloned->setDescriptions($source->getDescriptions());
        $cloned->setDisabledCondition($source->getDisabledCondition());
        $cloned->setVisibleCondition($source->getVisibleCondition());
        $cloned->setColSpan($source->getColSpan());
        $cloned->setDefaultType($source->getDefaultType());
        $cloned->setRequired($source->isRequired());
        try {
            $cloned->setMultilingual($source->isMultilingual());
        } catch (\TypeError) {
            $cloned->setMultilingual(false);
        }
        $cloned->setSpaceAfter($source->getSpaceAfter());
        $cloned->setMinOccurs($source->getMinOccurs());
        $cloned->setMaxOccurs($source->getMaxOccurs());
        $cloned->setOnInvalid($source->getOnInvalid());

        $clonedTags = [];
        foreach ($source->getTags() as $tag) {
            $clonedTags[] = clone $tag;
        }
        $cloned->setTags($clonedTags);

        foreach ($source->getOptions() as $option) {
            $cloned->addOption($this->cloneOptionMetadata($option));
        }

        foreach ($source->getTypes() as $typeForm) {
            $cloned->addType(clone $typeForm);
        }

        return $cloned;
    }

    private function cloneOptionMetadata(OptionMetadata $option): OptionMetadata
    {
        $cloned = clone $option;
        if (OptionMetadata::TYPE_COLLECTION === $option->getType() && \is_array($option->getValue())) {
            $clonedChildren = [];
            foreach ($option->getValue() as $key => $childOption) {
                $clonedChildren[$key] = $this->cloneOptionMetadata($childOption);
            }
            $cloned->setValue($clonedChildren);
        }

        return $cloned;
    }

    private function mergeSections(SectionMetadata $primary, SectionMetadata $secondary): SectionMetadata
    {
        $merged = new SectionMetadata($primary->getName());
        $merged->setLabels([] !== $primary->getLabels() ? $primary->getLabels() : $secondary->getLabels());
        $merged->setDescriptions([] !== $primary->getDescriptions() ? $primary->getDescriptions() : $secondary->getDescriptions());
        $merged->setColSpan($primary->getColSpan());
        $merged->setDisabledCondition($primary->getDisabledCondition() ?? $secondary->getDisabledCondition());
        $merged->setVisibleCondition($primary->getVisibleCondition() ?? $secondary->getVisibleCondition());

        foreach ($primary->getItems() as $item) {
            $merged->addItem($item);
        }
        foreach ($secondary->getItems() as $key => $item) {
            if (!isset($merged->getItems()[$key])) {
                $merged->addItem($item);
            }
        }

        return $merged;
    }

    private function loadSharedSection(string $formKey, string $sectionName): ?SectionMetadata
    {
        if (isset($this->sharedSectionPrototypes[$formKey])) {
            return $this->cloneSectionDeep($this->sharedSectionPrototypes[$formKey]);
        }

        $xmlPath = \dirname(__DIR__, 2) . '/config/forms/' . $formKey . '.xml';
        if (!\is_file($xmlPath)) {
            return null;
        }

        if (null !== $this->formXmlLoader) {
            try {
                $formMetadata = $this->formXmlLoader->load($xmlPath);
                $section = $formMetadata->getItems()[$sectionName] ?? null;
                if ($section instanceof SectionMetadata) {
                    $this->sharedSectionPrototypes[$formKey] = $section;

                    return $this->cloneSectionDeep($section);
                }
            } catch (\Throwable) {
                // Fallback to SimpleXML loader below
            }
        }

        $section = $this->parseSectionFromXmlFile($xmlPath, $sectionName);
        if (null !== $section) {
            $this->sharedSectionPrototypes[$formKey] = $section;

            return $this->cloneSectionDeep($section);
        }

        return null;
    }

    private function cloneSectionDeep(SectionMetadata $section): SectionMetadata
    {
        $cloned = new SectionMetadata($section->getName());
        $cloned->setLabels($section->getLabels());
        $cloned->setDescriptions($section->getDescriptions());
        $cloned->setColSpan($section->getColSpan());
        $cloned->setDisabledCondition($section->getDisabledCondition());
        $cloned->setVisibleCondition($section->getVisibleCondition());

        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $cloned->addItem($this->cloneSectionDeep($item));
            } elseif ($item instanceof FieldMetadata) {
                $cloned->addItem($this->cloneFieldMetadata($item, $item->getName()));
            }
        }

        return $cloned;
    }

    /**
     * Lightweight SimpleXML parser for shared `config/forms/block_suite_*.xml` files when `FormXmlLoader`
     * is not injected (e.g. in standalone unit tests).
     */
    private function parseSectionFromXmlFile(string $xmlPath, string $sectionName): ?SectionMetadata
    {
        $xml = @\simplexml_load_file($xmlPath);
        if (false === $xml || !isset($xml->properties)) {
            return null;
        }

        foreach ($xml->properties->section as $sectionNode) {
            $name = (string) ($sectionNode['name'] ?? '');
            if ($name !== $sectionName) {
                continue;
            }

            $section = new SectionMetadata($name);
            $section->setLabels($this->extractXmlTitles($sectionNode->meta ?? null));

            if (isset($sectionNode->properties)) {
                foreach ($sectionNode->properties->children() as $childNode) {
                    $tagName = $childNode->getName();
                    if ('property' === $tagName || 'block' === $tagName) {
                        $field = $this->parseXmlFieldNode($childNode, 'block' === $tagName);
                        if (null !== $field) {
                            $section->addItem($field);
                        }
                    }
                }
            }

            return $section;
        }

        return null;
    }

    private function parseXmlFieldNode(\SimpleXMLElement $node, bool $isBlock): ?FieldMetadata
    {
        $name = (string) ($node['name'] ?? '');
        if ('' === $name) {
            return null;
        }

        $field = new FieldMetadata($name);
        $field->setType($isBlock ? 'block' : (string) ($node['type'] ?? 'text_line'));
        $field->setLabels($this->extractXmlTitles($node->meta ?? null));
        $field->setDescriptions($this->extractXmlInfoTexts($node->meta ?? null));

        if (isset($node['colspan'])) {
            $field->setColSpan((int) $node['colspan']);
        }
        $field->setRequired('true' === (string) ($node['mandatory'] ?? 'false'));
        $field->setMultilingual('false' !== (string) ($node['multilingual'] ?? 'true'));

        if (isset($node['default-type'])) {
            $field->setDefaultType((string) $node['default-type']);
        }
        if (isset($node['minOccurs'])) {
            $field->setMinOccurs((int) $node['minOccurs']);
        }
        if (isset($node['maxOccurs'])) {
            $field->setMaxOccurs((int) $node['maxOccurs']);
        }
        if (isset($node['onInvalid'])) {
            $field->setOnInvalid((string) $node['onInvalid']);
        }
        if (isset($node['visibleCondition'])) {
            $field->setVisibleCondition((string) $node['visibleCondition']);
        }
        if (isset($node['disabledCondition'])) {
            $field->setDisabledCondition((string) $node['disabledCondition']);
        }

        if (isset($node->params)) {
            foreach ($node->params->param as $paramNode) {
                $field->addOption($this->parseXmlParamNode($paramNode));
            }
        }

        if (isset($node->types)) {
            foreach ($node->types->type as $typeNode) {
                $typeName = (string) ($typeNode['name'] ?? '');
                if ('' === $typeName) {
                    continue;
                }
                $typeForm = new FormMetadata();
                $typeForm->setKey($typeName);
                $typeForm->setTitles($this->extractXmlTitles($typeNode->meta ?? null));
                if (isset($typeNode->properties)) {
                    foreach ($typeNode->properties->children() as $subNode) {
                        $subTag = $subNode->getName();
                        if ('property' === $subTag || 'block' === $subTag) {
                            $subField = $this->parseXmlFieldNode($subNode, 'block' === $subTag);
                            if (null !== $subField) {
                                $typeForm->addItem($subField);
                            }
                        }
                    }
                }
                $field->addType($typeForm);
            }
        }

        return $field;
    }

    private function parseXmlParamNode(\SimpleXMLElement $paramNode): OptionMetadata
    {
        $option = new OptionMetadata();
        $option->setName((string) ($paramNode['name'] ?? ''));
        $type = (string) ($paramNode['type'] ?? OptionMetadata::TYPE_STRING);
        $option->setType($type);
        $option->setTitles($this->extractXmlTitles($paramNode->meta ?? null));

        if (OptionMetadata::TYPE_COLLECTION === $type) {
            $children = [];
            foreach ($paramNode->param as $childParam) {
                $children[] = $this->parseXmlParamNode($childParam);
            }
            $option->setValue($children);
        } else {
            $option->setValue(isset($paramNode['value']) ? (string) $paramNode['value'] : null);
        }

        return $option;
    }

    /**
     * @return array<string, string>
     */
    private function extractXmlTitles(?\SimpleXMLElement $metaNode): array
    {
        if (null === $metaNode || !isset($metaNode->title)) {
            return [];
        }

        $titles = [];
        foreach ($metaNode->title as $titleNode) {
            $lang = (string) ($titleNode['lang'] ?? 'en');
            $titles[$lang] = (string) $titleNode;
        }

        return $titles;
    }

    /**
     * @return array<string, string>
     */
    private function extractXmlInfoTexts(?\SimpleXMLElement $metaNode): array
    {
        if (null === $metaNode || !isset($metaNode->info_text)) {
            return [];
        }

        $texts = [];
        foreach ($metaNode->info_text as $infoNode) {
            $lang = (string) ($infoNode['lang'] ?? 'en');
            $texts[$lang] = (string) $infoNode;
        }

        return $texts;
    }

    /**
     * Injects compile-time options into `dynamic_source`, `dynamic_mapping`, and `dynamic_condition_field`.
     */
    private function populateDynamicSectionOptions(
        SectionMetadata $dynamicSection,
        FormMetadata $blockForm,
        SectionMetadata $contentSection,
        string $locale,
    ): void {
        $items = $dynamicSection->getItems();
        $isMultipleBlock = $this->isMultipleBlock($contentSection);
        $registry = $this->dynamicContentHydrator instanceof DynamicContentHydrator
            ? $this->dynamicContentHydrator->getRegistry()
            : new DynamicContentSourceRegistry();

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
                        $opt->setTitle($tf['label'] . ' (' . $tf['name'] . ')', $locale);
                        $targetOpts[] = $opt;
                    }
                    $targetCollection = new OptionMetadata();
                    $targetCollection->setName('values');
                    $targetCollection->setType(OptionMetadata::TYPE_COLLECTION);
                    $targetCollection->setValue($targetOpts);
                    $targetSelect->addOption($targetCollection);

                    if (isset($targetFields[0]) && null === $targetSelect->findOption('default_value')) {
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

                    if (isset($sourceFields[0]) && null === $sourceSelect->findOption('default_value')) {
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
    }

    private function populatePresetSectionOptions(SectionMetadata $advancedSection, string $blockKey): void
    {
        $presetField = $advancedSection->getItems()['preset'] ?? null;
        if (!$presetField instanceof FieldMetadata) {
            return;
        }

        $typeOption = new OptionMetadata();
        $typeOption->setName('component_type');
        $typeOption->setValue($blockKey);
        $presetField->addOption($typeOption);

        if (null !== $this->presetProvider) {
            $presets = $this->presetProvider->getPresets($blockKey);
            $presetsJsonOption = new OptionMetadata();
            $presetsJsonOption->setName('presets_json');
            $presetsJsonOption->setValue((string) \json_encode($presets));
            $presetField->addOption($presetsJsonOption);
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

    /**
     * @return list<array{name: string, label: string}>
     */
    private function discoverBlockTargetFields(SectionMetadata $contentSection, string $locale): array
    {
        /** @var array<string, array{name: string, label: string}> $fieldsByName */
        $fieldsByName = [];

        foreach ($this->collectContentFieldMetadata($contentSection->getItems()) as $field) {
            $fieldName = $field->getName();
            $fieldType = $this->getFieldType($field);

            if ('items' === $fieldName && 'block' === $fieldType) {
                foreach ($field->getTypes() as $childForm) {
                    foreach ($this->collectContentFieldMetadata($childForm->getItems()) as $childField) {
                        $childName = $childField->getName();
                        $childType = $this->getFieldType($childField);
                        if (isset(self::EXCLUDED_TARGET_FIELDS[$childName]) || 'block' === $childType || \str_starts_with($childName, 'props/')) {
                            continue;
                        }
                        $label = $childField->getLabel($locale) ?? $childField->getLabel('en') ?? \ucwords(\str_replace(['_', '-'], ' ', $childName));
                        $fieldsByName[$childName] ??= [
                            'name' => $childName,
                            'label' => $label,
                        ];
                    }
                }

                continue;
            }

            if (isset(self::EXCLUDED_TARGET_FIELDS[$fieldName]) || 'block' === $fieldType || \str_starts_with($fieldName, 'props/')) {
                continue;
            }

            $label = $field->getLabel($locale) ?? $field->getLabel('en') ?? \ucwords(\str_replace(['_', '-'], ' ', $fieldName));
            $fieldsByName[$fieldName] = [
                'name' => $fieldName,
                'label' => $label,
            ];
        }

        if ([] === $fieldsByName) {
            return [
                ['name' => 'title', 'label' => 'Title'],
                ['name' => 'subtitle', 'label' => 'Subtitle'],
                ['name' => 'content', 'label' => 'Content'],
                ['name' => 'image', 'label' => 'Image'],
                ['name' => 'link', 'label' => 'Link'],
                ['name' => 'icon', 'label' => 'Icon'],
            ];
        }

        return \array_values($fieldsByName);
    }

    /**
     * Collects FieldMetadata items exclusively from root fields and `content`/`media` sections
     * (including `__tabs -> content`), skipping `settings`, `style`, `layout`, `general`, `dynamic_content`, and `advanced`.
     *
     * @param array<array-key, ItemMetadata> $items
     *
     * @return list<FieldMetadata>
     */
    private function collectContentFieldMetadata(array $items): array
    {
        if (isset($items[self::TABS_SECTION_NAME]) && $items[self::TABS_SECTION_NAME] instanceof SectionMetadata) {
            $contentTab = $items[self::TABS_SECTION_NAME]->getItems()['content'] ?? null;

            return $contentTab instanceof SectionMetadata
                ? $this->collectContentFieldMetadata($contentTab->getItems())
                : [];
        }

        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof FieldMetadata) {
                $fields[] = $item;
            } elseif ($item instanceof SectionMetadata && \in_array($item->getName(), ['content', 'media'], true)) {
                foreach ($this->collectContentFieldMetadata($item->getItems()) as $subField) {
                    $fields[] = $subField;
                }
            }
        }

        return $fields;
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    private function discoverAllSourceFields(DynamicContentSourceRegistry $registry, string $locale): array
    {
        /** @var array<string, array{name: string, label: string}> $fieldsByName */
        $fieldsByName = [];

        foreach ($registry->getProviders() as $provider) {
            foreach ($provider->getSourceFields($locale) as $field) {
                $name = $field['name'];
                $fieldsByName[$name] ??= [
                    'name' => $name,
                    'label' => $field['label'],
                ];
            }
        }

        if ([] === $fieldsByName) {
            return [
                ['name' => 'title', 'label' => 'Title'],
                ['name' => 'headline', 'label' => 'Headline'],
                ['name' => 'subtitle', 'label' => 'Subtitle'],
                ['name' => 'summary', 'label' => 'Summary'],
                ['name' => 'description', 'label' => 'Description'],
                ['name' => 'image', 'label' => 'Image'],
                ['name' => 'url', 'label' => 'URL'],
                ['name' => 'link', 'label' => 'Link'],
                ['name' => 'badge', 'label' => 'Badge'],
                ['name' => 'date', 'label' => 'Publish Date'],
            ];
        }

        return \array_values($fieldsByName);
    }

    /**
     * Recursively traverses form items (sections, inline block types, and block fields)
     * to enhance inline structural blocks (`row_item`, `column_item`) with tabs and populate
     * any `<block>` field carrying `<tag name="sulu_block_suite.auto_blocks" .../>`.
     *
     * @param array<array-key, ItemMetadata> $items
     * @param list<string> $ancestorKeys
     * @param array<int, true> $visitedFormIds
     */
    private function enhanceAutoBlocksInItems(
        array $items,
        FormMetadata $rootForm,
        string $locale,
        array $ancestorKeys,
        array $visitedFormIds = [],
    ): void {
        $visitedFormIds[\spl_object_id($rootForm)] = true;

        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $this->enhanceAutoBlocksInItems($item->getItems(), $rootForm, $locale, $ancestorKeys, $visitedFormIds);

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            // First traverse inline sub-types (e.g. rows -> row_item -> columns -> column_item, or items -> card/button/badge)
            $existingTypes = $item->getTypes();
            foreach ($existingTypes as $typeKey => $typeForm) {
                $formObjectId = \spl_object_id($typeForm);
                if (\is_string($typeKey) && !$typeForm->hasTag('sulu.global_block') && !isset($visitedFormIds[$formObjectId])) {
                    if ($this->shouldEnhanceInlineTypeWithTabs($typeForm)) {
                        $tabsTag = $typeForm->findTag(self::TABS_TAG);
                        $sharedAttr = $tabsTag?->getAttribute('shared');
                        $includeShared = \in_array($typeKey, ['row_item', 'column_item'], true)
                            || 'true' === $sharedAttr
                            || true === $sharedAttr;
                        $namespaceAttr = $tabsTag?->getAttribute('namespace_props');
                        $namespaceProps = null !== $namespaceAttr
                            ? ('true' === $namespaceAttr || true === $namespaceAttr)
                            : $includeShared;
                        $this->enhanceBlockTabs(
                            $typeForm,
                            $locale,
                            includeSharedTabs: $includeShared,
                            namespaceProps: $namespaceProps,
                        );
                    }

                    $nextVisited = $visitedFormIds;
                    $nextVisited[$formObjectId] = true;

                    $this->enhanceAutoBlocksInItems(
                        $typeForm->getItems(),
                        $rootForm,
                        $locale,
                        [...$ancestorKeys, $typeKey],
                        $nextVisited,
                    );
                }
            }

            $autoBlocksTag = $this->findFieldTag($item, self::AUTO_BLOCKS_TAG);
            if (null === $autoBlocksTag) {
                continue;
            }

            $attributes = $autoBlocksTag->getAttributes();
            $excludeAttr = isset($attributes['exclude']) && \is_string($attributes['exclude']) ? $attributes['exclude'] : '';
            $excludedTypes = \array_values(\array_filter(
                \array_map('trim', \explode(',', $excludeAttr)),
                static fn (string $val): bool => '' !== $val,
            ));

            $preferredDefault = isset($attributes['default']) && \is_string($attributes['default']) && '' !== \trim($attributes['default'])
                ? \trim($attributes['default'])
                : null;

            $this->enhanceBlockField(
                $item,
                $rootForm,
                $locale,
                \array_values(\array_unique([...$excludedTypes, ...$ancestorKeys])),
                $preferredDefault,
            );
        }
    }

    private function shouldEnhanceInlineTypeWithTabs(FormMetadata $typeForm): bool
    {
        $typeKey = $typeForm->getKey();
        if (\in_array($typeKey, ['row_item', 'column_item'], true)) {
            return true;
        }

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

        $excludedFieldNames = [
            'hover_image' => true,
            'hover_video' => true,
            'image_url' => true,
            'hover_image_url' => true,
            'video_url' => true,
            'hover_video_url' => true,
            'link_title' => true,
            'aspect_ratio_width' => true,
            'aspect_ratio_height' => true,
            'zoom' => true,
        ];

        $primaryTextNames = [
            'title' => true,
            'headline' => true,
            'override_title' => true,
            'header_title' => true,
            'author' => true,
            'location_query' => true,
            'target_id' => true,
            'label' => true,
            'term' => true,
        ];

        foreach ($fields as $field) {
            $name = $field->getName();
            if (isset($excludedFieldNames[$name]) || \str_starts_with($name, 'props/')) {
                continue;
            }

            $type = $this->getFieldType($field);
            $priority = match ($type) {
                'single_reusable_block_selection' => 2048,
                'single_media_selection', 'media_selection' => 'image' === $name ? 2048 : 1900,
                'text_line' => isset($primaryTextNames[$name]) ? 1024 : 768,
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
     * @param list<string> $excludedTypes
     */
    private function enhanceBlockTypes(FormMetadata $formMetadata, string $locale, string $propertyName = 'blocks', array $excludedTypes = ['reusable', 'main_content']): void
    {
        $items = $formMetadata->getItems();
        if (!isset($items[$propertyName]) || !$items[$propertyName] instanceof FieldMetadata) {
            return;
        }

        /** @var FieldMetadata $blocksProperty */
        $blocksProperty = $items[$propertyName];
        if ($blocksProperty->hasTag(self::AUTO_BLOCKS_TAG)) {
            return;
        }

        $this->enhanceBlockField($blocksProperty, $formMetadata, $locale, $excludedTypes, null);
    }

    /**
     * @param list<string> $excludedTypes
     */
    private function enhanceBlockField(
        FieldMetadata $blocksProperty,
        FormMetadata $rootForm,
        string $locale,
        array $excludedTypes = [],
        ?string $preferredDefault = null,
    ): void {
        $blockForms = $this->getRegisteredBlockForms($locale);
        if ([] === $blockForms) {
            return;
        }

        $excludedMap = \array_fill_keys(['default', '', $rootForm->getKey(), ...$excludedTypes], true);
        $rootSchema = $rootForm->getSchema();
        $existingTypes = $blocksProperty->getTypes();

        $addedCount = 0;
        foreach ($blockForms as $typeKey => $blockForm) {
            if (isset($excludedMap[$typeKey])) {
                continue;
            }

            $this->enhanceBlockTabs($blockForm, $locale, includeSharedTabs: true);
            $this->enhanceBlockPreviewTags($blockForm);

            if (!isset($existingTypes[$typeKey])) {
                $blocksProperty->addType($blockForm);
                $rootSchema->addDefinition($typeKey, $blockForm->getSchema());
                ++$addedCount;
            } else {
                ++$addedCount;
            }
        }

        if ($addedCount > 0 && isset($existingTypes['default'])) {
            $blocksProperty->removeType('default');
        }

        $updatedTypes = $blocksProperty->getTypes();
        if (null !== $preferredDefault && isset($updatedTypes[$preferredDefault])) {
            $blocksProperty->setDefaultType($preferredDefault);

            return;
        }

        $currentDefault = $blocksProperty->getDefaultType();
        if ((null === $currentDefault || !isset($updatedTypes[$currentDefault]) || 'default' === $currentDefault) && [] !== $updatedTypes) {
            $firstTypeKey = (string) \array_key_first($updatedTypes);
            $blocksProperty->setDefaultType($firstTypeKey);
        }
    }

    /**
     * @return list<string>
     */
    public function discoverAvailableBlockTypes(?string $locale = null): array
    {
        $resolvedLocale = $locale ?? 'en';
        $types = [];
        $excluded = ['reusable', 'main_content', 'default', 'common', ''];

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

        if (null !== $this->translator) {
            $key = 'sulu_block_suite.block_' . $blockType;
            $translated = $this->translator->trans($key, [], 'admin', $locale);
            if ($translated !== $key) {
                return $translated;
            }

            $translatedDefault = $this->translator->trans($key, [], null, $locale);
            if ($translatedDefault !== $key) {
                return $translatedDefault;
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

                return $this->registeredBlocksCache[$locale] = $blockForms;
            }
        } catch (\Throwable) {
            // Gracefully ignore early initialization or cache warmup
        } finally {
            $this->isLoadingRegisteredBlocks = false;
        }

        return $this->registeredBlocksCache[$locale] = [];
    }
}
