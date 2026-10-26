<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\Loader\FormXmlLoader;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;

/**
 * Deep module responsible for loading shared Sulu XML form sections (`block_suite_advanced`, `block_suite_dynamic`, etc.),
 * deep-cloning section/field/option graphs, normalizing scalar default values, and namespacing non-content fields under `props/`.
 */
final class SharedXmlSectionLoader
{
    /**
     * @var array<string, SectionMetadata>
     */
    private array $sharedSectionPrototypes = [];

    public function __construct(
        private readonly ?FormXmlLoader $formXmlLoader = null,
    ) {
    }

    public function loadSharedSection(string $formKey, string $sectionName): ?SectionMetadata
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

    /**
     * Recursively prefixes all FieldMetadata items inside a non-content section with `$prefix` (e.g. `props/`).
     */
    public function namespaceSectionFields(SectionMetadata $section, string $prefix): SectionMetadata
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
                if ('props' === $rawName && 'preset_selection' === $this->getFieldType($item) && 'false' === $item->getVisibleCondition()) {
                    $clonedSection->addItem($this->cloneFieldMetadata($item, 'props'));

                    continue;
                }

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

    public function normalizeSectionFieldDefaults(SectionMetadata $section): void
    {
        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $this->normalizeSectionFieldDefaults($item);
            } elseif ($item instanceof FieldMetadata) {
                $this->normalizeFieldDefaultOption($item);
            }
        }
    }

    public function mergeSections(SectionMetadata $primary, SectionMetadata $secondary): SectionMetadata
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

    public function cloneSectionDeep(SectionMetadata $section): SectionMetadata
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

    public function normalizeFieldDefaultOption(FieldMetadata $field): void
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
            $cloned->addType($this->cloneFormMetadata($typeForm));
        }

        return $cloned;
    }

    private function cloneFormMetadata(FormMetadata $source): FormMetadata
    {
        $cloned = new FormMetadata();
        $cloned->setKey($source->getKey());
        $cloned->setTitles($source->getTitles());

        $clonedTags = [];
        foreach ($source->getTags() as $tag) {
            $clonedTags[] = clone $tag;
        }
        $cloned->setTags($clonedTags);

        foreach ($source->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $cloned->addItem($this->cloneSectionDeep($item));
            } elseif ($item instanceof FieldMetadata) {
                $cloned->addItem($this->cloneFieldMetadata($item, $item->getName()));
            }
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

    /**
     * Lightweight SimpleXML parser for shared `config/forms/block_suite_*.xml` files when `FormXmlLoader`
     * is not injected (e.g. in standalone unit tests).
     */
    private function parseSectionFromXmlFile(string $xmlPath, string $sectionName): ?SectionMetadata
    {
        $dom = new \DOMDocument();
        if (false === @$dom->load($xmlPath)) {
            return null;
        }
        @$dom->xinclude();
        $xml = \simplexml_import_dom($dom);
        if (null === $xml || !isset($xml->properties)) {
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

        if (isset($node->tag)) {
            foreach ($node->tag as $tagNode) {
                $field->addTag($this->parseXmlTagNode($tagNode));
            }
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
                if (isset($typeNode->tag)) {
                    foreach ($typeNode->tag as $tagNode) {
                        $typeForm->addTag($this->parseXmlTagNode($tagNode));
                    }
                }
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

    private function parseXmlTagNode(\SimpleXMLElement $tagNode): TagMetadata
    {
        $tag = new TagMetadata();
        $tag->setName((string) ($tagNode['name'] ?? ''));
        if (isset($tagNode['priority'])) {
            $tag->setPriority((int) $tagNode['priority']);
        }

        $attributes = [];
        foreach ($tagNode->attributes() as $attrName => $attrVal) {
            if ('name' === $attrName || 'priority' === $attrName) {
                continue;
            }
            $attributes[$attrName] = (string) $attrVal;
        }
        $tag->setAttributes($attributes);

        return $tag;
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

    private function getFieldType(FieldMetadata $field): ?string
    {
        try {
            return $field->getType();
        } catch (\Error) {
            return null;
        }
    }
}
