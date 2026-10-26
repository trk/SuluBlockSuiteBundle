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
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;

/**
 * Deep internal module responsible for populating custom select field types
 * (`single_template_selection`, `template_selection`, `single_webspace_select`, `webspace_select`,
 * and `navigation_context`) and handling multi-webspace field visibility/colSpan adjustments.
 */
final class FormSelectFieldPopulator
{
    /**
     * @var array<string, array<string, list<array{key: string, title: string, group: string}>>>
     */
    private array $templateDescriptorsCache = [];

    /**
     * @var array<string, true>
     */
    private array $loadingTemplateTypes = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $navigationContextsCache = [];

    private bool $isDiscoveringNavigationContexts = false;

    public function __construct(
        private readonly ?string $projectDirectory = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
        private readonly ?WebspaceManagerInterface $webspaceManager = null,
        private readonly bool $multiWebspaceEnabled = true,
    ) {
    }

    public function cacheTemplateDescriptors(
        TypedFormMetadata $formMetadata,
        string $templateType,
        string $locale,
    ): void {
        $descriptors = [];
        foreach ($formMetadata->getForms() as $key => $form) {
            $resolvedKey = \is_string($key) && '' !== \trim($key) ? \trim($key) : \trim($form->getKey());
            if ('' === $resolvedKey) {
                continue;
            }

            $titles = $form->getTitles();
            $title = $titles[$locale] ?? $titles['en'] ?? (\reset($titles) ?: \ucfirst(\str_replace(['_', '-'], ' ', $resolvedKey)));
            $group = $form->getGroup();

            $descriptors[] = [
                'key' => $resolvedKey,
                'title' => '' !== \trim($title) ? \trim($title) : $resolvedKey,
                'group' => \is_string($group) && '' !== \trim($group) ? \trim($group) : 'default',
            ];
        }

        $this->templateDescriptorsCache[$templateType][$locale] = $descriptors;
    }

    /**
     * @param array<array-key, ItemMetadata> $items
     * @param (callable(string): array<string, FormMetadata>)|null $blockFormsProvider
     */
    public function populateSelectFieldsInItems(
        array $items,
        string $locale,
        ?callable $blockFormsProvider = null,
    ): bool {
        $hasHiddenWebspaceField = false;
        $anyWebspaceFieldHidden = false;

        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                if ($this->populateSelectFieldsInItems($item->getItems(), $locale, $blockFormsProvider)) {
                    $anyWebspaceFieldHidden = true;
                }

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            $fieldType = $this->getFieldType($item);
            if ('single_template_selection' === $fieldType || 'template_selection' === $fieldType) {
                $this->populateTemplateSelectionOptions(
                    $item,
                    $locale,
                    'single_template_selection' === $fieldType,
                    $blockFormsProvider,
                );
            } elseif ('single_webspace_select' === $fieldType || 'webspace_select' === $fieldType) {
                $this->populateWebspaceSelectOptions($item, $locale, 'single_webspace_select' === $fieldType);
                if (!$this->multiWebspaceEnabled) {
                    $hasHiddenWebspaceField = true;
                    $anyWebspaceFieldHidden = true;
                }
            }

            foreach ($item->getTypes() as $subTypeForm) {
                if ($this->populateSelectFieldsInItems($subTypeForm->getItems(), $locale, $blockFormsProvider)) {
                    $anyWebspaceFieldHidden = true;
                }
            }
        }

        if ($hasHiddenWebspaceField) {
            foreach ($items as $item) {
                if (
                    $item instanceof FieldMetadata
                    && 'false' !== $item->getVisibleCondition()
                    && 6 === $item->getColSpan()
                ) {
                    $item->setColSpan(12);
                }
            }
        }

        return $anyWebspaceFieldHidden;
    }

    public function populateNavigationContextFields(SectionMetadata $section, string $locale): void
    {
        foreach ($section->getItems() as $item) {
            if ($item instanceof SectionMetadata) {
                $this->populateNavigationContextFields($item, $locale);
            } elseif ($item instanceof FieldMetadata && 'navigation_context' === $item->getName() && 'single_select' === $this->getFieldType($item)) {
                $this->populateNavigationContextOptions($item, $locale);
            }
        }
    }

    /**
     * @param (callable(string): array<string, FormMetadata>)|null $blockFormsProvider
     */
    private function populateTemplateSelectionOptions(
        FieldMetadata $field,
        string $locale,
        bool $isSingle,
        ?callable $blockFormsProvider = null,
    ): void {
        $templateTypes = $this->extractTemplateSelectionTypes($field);
        $allowedGroups = $this->extractOptionStringSet($field, ['groups', 'group']);
        $excludedTemplates = $this->extractOptionStringSet($field, ['excluded_templates', 'exclude']);

        $field->setType($isSingle ? 'single_select' : 'select');

        /** @var array<string, OptionMetadata> $optionsByKey */
        $optionsByKey = [];
        $existingValuesOption = $field->findOption('values');
        if (null !== $existingValuesOption && \is_array($existingValuesOption->getValue())) {
            foreach ($existingValuesOption->getValue() as $existingOpt) {
                $existingName = $existingOpt->getName();
                if (\is_string($existingName) && '' !== $existingName) {
                    $optionsByKey[$existingName] = $existingOpt;
                }
            }
        }

        foreach ($templateTypes as $templateType) {
            foreach ($this->discoverTemplateDescriptorsForType($templateType, $locale, $blockFormsProvider) as $descriptor) {
                $key = $descriptor['key'];
                if (isset($excludedTemplates[$key])) {
                    continue;
                }

                if ([] !== $allowedGroups && !isset($allowedGroups[$descriptor['group']])) {
                    continue;
                }

                if (isset($optionsByKey[$key])) {
                    $optionsByKey[$key]->setTitle($descriptor['title'], $locale);

                    continue;
                }

                $opt = new OptionMetadata();
                $opt->setName($key);
                $opt->setValue($key);
                $opt->setTitle($descriptor['title'], $locale);
                $optionsByKey[$key] = $opt;
            }
        }

        $options = \array_values($optionsByKey);
        \usort(
            $options,
            static fn (OptionMetadata $a, OptionMetadata $b): int => \strnatcasecmp(
                $a->getTitle($locale) ?? (string) $a->getName(),
                $b->getTitle($locale) ?? (string) $b->getName(),
            ),
        );

        if ($isSingle && !$field->isRequired() && !isset($optionsByKey[''])) {
            $emptyOpt = new OptionMetadata();
            $emptyOpt->setName('');
            $emptyOpt->setValue('');
            $emptyOpt->setTitle(
                $this->translate('sulu_block_suite.template_selection_none', $locale, '— None (Custom Layout) —'),
                $locale,
            );
            \array_unshift($options, $emptyOpt);
        }

        $valuesCollection = new OptionMetadata();
        $valuesCollection->setName('values');
        $valuesCollection->setType(OptionMetadata::TYPE_COLLECTION);
        $valuesCollection->setValue($options);
        $field->addOption($valuesCollection);

        if ($isSingle && $field->isRequired() && null === $field->findOption('default_value') && isset($options[0])) {
            $defaultOption = new OptionMetadata();
            $defaultOption->setName('default_value');
            $defaultOption->setType(OptionMetadata::TYPE_STRING);
            $defaultOption->setValue((string) $options[0]->getName());
            $field->addOption($defaultOption);
        }
    }

    private function populateWebspaceSelectOptions(FieldMetadata $field, string $locale, bool $isSingle): void
    {
        $field->setType($isSingle ? 'single_select' : 'select');

        if (!$this->multiWebspaceEnabled) {
            $field->setRequired(false);
            $field->setVisibleCondition('false');

            $globalOpt = new OptionMetadata();
            $globalOpt->setName('_global');
            $globalOpt->setValue('_global');
            $globalOpt->setTitle($this->translate('sulu_multi_webspace.global_all_webspaces', $locale, 'Global (All Webspaces)'), $locale);

            $valuesCollection = new OptionMetadata();
            $valuesCollection->setName('values');
            $valuesCollection->setType(OptionMetadata::TYPE_COLLECTION);
            $valuesCollection->setValue([$globalOpt]);
            $field->addOption($valuesCollection);

            if ($isSingle) {
                $defaultOption = new OptionMetadata();
                $defaultOption->setName('default_value');
                $defaultOption->setType(OptionMetadata::TYPE_STRING);
                $defaultOption->setValue('_global');
                $field->addOption($defaultOption);
            }

            return;
        }

        $includeGlobalOption = $field->findOption('include_global');
        $rawIncludeGlobal = $includeGlobalOption?->getValue();
        $includeGlobal = true === $rawIncludeGlobal || 'true' === $rawIncludeGlobal || 1 === $rawIncludeGlobal || '1' === $rawIncludeGlobal;

        $autoDefaultOption = $field->findOption('auto_default');
        $rawAutoDefault = $autoDefaultOption?->getValue();
        $autoDefault = null === $autoDefaultOption
            || (false !== $rawAutoDefault && 'false' !== $rawAutoDefault && 0 !== $rawAutoDefault && '0' !== $rawAutoDefault);

        /** @var list<OptionMetadata> $options */
        $options = [];
        if ($includeGlobal) {
            $globalOpt = new OptionMetadata();
            $globalOpt->setName('_global');
            $globalOpt->setValue('_global');
            $globalOpt->setTitle($this->translate('sulu_multi_webspace.global_all_webspaces', $locale, 'Global (All Webspaces)'), $locale);
            $options[] = $globalOpt;
        }

        foreach ($this->discoverWebspaces() as $webspaceKey => $webspaceName) {
            $opt = new OptionMetadata();
            $opt->setName($webspaceKey);
            $opt->setValue($webspaceKey);
            $opt->setTitle($webspaceName, $locale);
            $options[] = $opt;
        }

        $valuesCollection = new OptionMetadata();
        $valuesCollection->setName('values');
        $valuesCollection->setType(OptionMetadata::TYPE_COLLECTION);
        $valuesCollection->setValue($options);
        $field->addOption($valuesCollection);

        if ($isSingle && $autoDefault && null === $field->findOption('default_value') && isset($options[0])) {
            $defaultOption = new OptionMetadata();
            $defaultOption->setName('default_value');
            $defaultOption->setType(OptionMetadata::TYPE_STRING);
            $defaultOption->setValue((string) $options[0]->getName());
            $field->addOption($defaultOption);
        }
    }

    /**
     * @return array<string, string>
     */
    private function discoverWebspaces(): array
    {
        /** @var array<string, string> $webspaces */
        $webspaces = [];

        if (null !== $this->webspaceManager) {
            try {
                foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
                    $key = \trim($webspace->getKey());
                    if ('' === $key) {
                        continue;
                    }
                    $name = \trim($webspace->getName());
                    $webspaces[$key] = '' !== $name ? $name : $key;
                }
            } catch (\Throwable) {
                // Fallback to XML scan below
            }
        }

        if ([] === $webspaces && null !== $this->projectDirectory) {
            $webspacesDir = \rtrim($this->projectDirectory, '/') . '/config/webspaces';
            if (\is_dir($webspacesDir)) {
                $files = \glob($webspacesDir . '/*.xml');
                if (false !== $files) {
                    foreach ($files as $file) {
                        $xml = @\simplexml_load_file($file);
                        if (false === $xml) {
                            continue;
                        }
                        $key = \trim((string) ($xml->key ?? \basename($file, '.xml')));
                        if ('' === $key) {
                            continue;
                        }
                        $name = \trim((string) ($xml->name ?? ''));
                        $webspaces[$key] = '' !== $name ? $name : $key;
                    }
                }
            }
        }

        return $webspaces;
    }

    /**
     * @return list<string>
     */
    private function extractTemplateSelectionTypes(FieldMetadata $field): array
    {
        $rawTypes = $this->extractOptionStringSet($field, ['template_type', 'types', 'resource_key']);
        if ([] === $rawTypes) {
            return ['page'];
        }

        $normalized = [];
        foreach (\array_keys($rawTypes) as $rawType) {
            $lower = \strtolower(\trim($rawType));
            if ('all' === $lower) {
                $normalized['page'] = true;
                $normalized['article'] = true;
                $normalized['snippet'] = true;

                continue;
            }

            $mapped = match ($lower) {
                'pages' => 'page',
                'articles' => 'article',
                'snippets' => 'snippet',
                'blocks' => 'block',
                default => $lower,
            };

            if ('' !== $mapped) {
                $normalized[$mapped] = true;
            }
        }

        return [] !== $normalized ? \array_keys($normalized) : ['page'];
    }

    /**
     * @param list<string> $optionNames
     *
     * @return array<string, true>
     */
    private function extractOptionStringSet(FieldMetadata $field, array $optionNames): array
    {
        $set = [];
        foreach ($optionNames as $name) {
            $opt = $field->findOption($name);
            if (null === $opt) {
                continue;
            }

            $val = $opt->getValue();
            if (\is_string($val)) {
                foreach (\explode(',', $val) as $part) {
                    $trimmed = \trim($part);
                    if ('' !== $trimmed) {
                        $set[$trimmed] = true;
                    }
                }
            } elseif (\is_array($val)) {
                foreach ($val as $child) {
                    $childVal = \is_string($child->getValue()) && '' !== \trim($child->getValue())
                        ? \trim($child->getValue())
                        : \trim((string) ($child->getName() ?? ''));
                    if ('' !== $childVal) {
                        $set[$childVal] = true;
                    }
                }
            }
        }

        return $set;
    }

    /**
     * @param (callable(string): array<string, FormMetadata>)|null $blockFormsProvider
     *
     * @return list<array{key: string, title: string, group: string}>
     */
    private function discoverTemplateDescriptorsForType(
        string $templateType,
        string $locale,
        ?callable $blockFormsProvider = null,
    ): array {
        if (isset($this->templateDescriptorsCache[$templateType][$locale])) {
            return $this->templateDescriptorsCache[$templateType][$locale];
        }

        if ('block' === $templateType && null !== $blockFormsProvider) {
            $descriptors = [];
            foreach ($blockFormsProvider($locale) as $key => $form) {
                $titles = $form->getTitles();
                $title = $titles[$locale] ?? $titles['en'] ?? (\reset($titles) ?: \ucfirst(\str_replace(['_', '-'], ' ', $key)));
                $descriptors[] = [
                    'key' => $key,
                    'title' => '' !== \trim($title) ? \trim($title) : $key,
                    'group' => 'default',
                ];
            }

            return $this->templateDescriptorsCache[$templateType][$locale] = $descriptors;
        }

        if (null !== $this->metadataProviderRegistry && !isset($this->loadingTemplateTypes[$templateType])) {
            $this->loadingTemplateTypes[$templateType] = true;
            try {
                $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
                $metadata = $provider->getMetadata($templateType, $locale, []);
                if ($metadata instanceof TypedFormMetadata) {
                    $this->cacheTemplateDescriptors($metadata, $templateType, $locale);

                    return $this->templateDescriptorsCache[$templateType][$locale] ?? [];
                }
            } catch (\Throwable) {
                // Fall back to XML scan below
            } finally {
                unset($this->loadingTemplateTypes[$templateType]);
            }
        }

        $descriptors = [];
        if (null !== $this->projectDirectory) {
            $dirName = match ($templateType) {
                'page', 'home' => 'pages',
                'article' => 'articles',
                'snippet' => 'snippets',
                'block' => 'blocks',
                default => $templateType,
            };
            $templatesDir = \rtrim($this->projectDirectory, '/') . '/config/templates/' . $dirName;
            if (\is_dir($templatesDir)) {
                $files = \glob($templatesDir . '/*.xml');
                if (false !== $files) {
                    foreach ($files as $file) {
                        $xml = @\simplexml_load_file($file);
                        if (false === $xml) {
                            continue;
                        }
                        $key = \trim((string) ($xml->key ?? \basename($file, '.xml')));
                        if ('' === $key) {
                            continue;
                        }
                        $titles = $this->extractXmlTitles($xml->meta ?? null);
                        $title = $titles[$locale] ?? $titles['en'] ?? \ucfirst(\str_replace(['_', '-'], ' ', $key));
                        $group = \trim((string) ($xml->group ?? 'default'));
                        $descriptors[] = [
                            'key' => $key,
                            'title' => $title,
                            'group' => '' !== $group ? $group : 'default',
                        ];
                    }
                }
            }
        }

        if (!isset($this->loadingTemplateTypes[$templateType])) {
            $this->templateDescriptorsCache[$templateType][$locale] = $descriptors;
        }

        return $descriptors;
    }

    private function populateNavigationContextOptions(FieldMetadata $field, string $locale): void
    {
        $discoveredContexts = $this->discoverWebspaceNavigationContexts($locale);
        if ([] === $discoveredContexts) {
            return;
        }

        /** @var array<string, OptionMetadata> $optionsByKey */
        $optionsByKey = [];
        $existingValuesOption = $field->findOption('values');
        if (null !== $existingValuesOption && \is_array($existingValuesOption->getValue())) {
            foreach ($existingValuesOption->getValue() as $existingOpt) {
                if (\is_string($existingOpt->getName()) && '' !== $existingOpt->getName()) {
                    $optionsByKey[$existingOpt->getName()] = $existingOpt;
                }
            }
        }

        foreach ($discoveredContexts as $contextKey => $contextTitle) {
            if (isset($optionsByKey[$contextKey])) {
                $optionsByKey[$contextKey]->setTitle($contextTitle, $locale);

                continue;
            }

            $opt = new OptionMetadata();
            $opt->setName($contextKey);
            $opt->setValue($contextKey);
            $opt->setTitle($contextTitle, $locale);
            $optionsByKey[$contextKey] = $opt;
        }

        $valuesCollection = new OptionMetadata();
        $valuesCollection->setName('values');
        $valuesCollection->setType(OptionMetadata::TYPE_COLLECTION);
        $valuesCollection->setValue(\array_values($optionsByKey));
        $field->addOption($valuesCollection);
    }

    /**
     * @return array<string, string>
     */
    private function discoverWebspaceNavigationContexts(string $locale): array
    {
        if (isset($this->navigationContextsCache[$locale])) {
            return $this->navigationContextsCache[$locale];
        }

        /** @var array<string, string> $contexts */
        $contexts = [];

        if (null !== $this->webspaceManager && !$this->isDiscoveringNavigationContexts) {
            $this->isDiscoveringNavigationContexts = true;
            try {
                foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
                    $navigation = $webspace->getNavigation();
                    foreach ($navigation->getContexts() as $navContext) {
                        $key = \trim($navContext->getKey());
                        if ('' === $key) {
                            continue;
                        }
                        $title = $navContext->getTitle($locale) ?? \ucfirst(\str_replace(['_', '-'], ' ', $key));
                        $contexts[$key] ??= $title;
                    }
                }
            } catch (\Throwable) {
                // Fallback to XML scan below
            } finally {
                $this->isDiscoveringNavigationContexts = false;
            }
        }

        if (null !== $this->projectDirectory) {
            $webspacesDir = \rtrim($this->projectDirectory, '/') . '/config/webspaces';
            if (\is_dir($webspacesDir)) {
                $files = \glob($webspacesDir . '/*.xml');
                if (false !== $files) {
                    foreach ($files as $file) {
                        $xml = @\simplexml_load_file($file);
                        if (false === $xml) {
                            continue;
                        }
                        $nodes = $xml->xpath('//*[local-name()="navigation"]/*[local-name()="contexts"]/*[local-name()="context"]') ?: [];
                        foreach ($nodes as $node) {
                            $key = \trim((string) ($node['key'] ?? ''));
                            if ('' === $key) {
                                continue;
                            }
                            $titles = $this->extractXmlTitles($node->meta ?? null);
                            $title = $titles[$locale] ?? $titles['en'] ?? \ucfirst(\str_replace(['_', '-'], ' ', $key));
                            $contexts[$key] ??= $title;
                        }
                    }
                }
            }
        }

        if (!$this->isDiscoveringNavigationContexts) {
            $this->navigationContextsCache[$locale] = $contexts;
        }

        return $contexts;
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

    private function getFieldType(FieldMetadata $field): string
    {
        try {
            return $field->getType();
        } catch (\Error) {
            return '';
        }
    }

    private function translate(string $key, string $locale, string $fallback): string
    {
        return AbstractDynamicContentSourceProvider::translateKey($key, $locale, $fallback, $this->translator);
    }
}
