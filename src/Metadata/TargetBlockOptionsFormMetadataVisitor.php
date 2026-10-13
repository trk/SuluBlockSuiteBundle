<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TargetBlockOptionsFormMetadataVisitor implements FormMetadataVisitorInterface, TypedFormMetadataVisitorInterface
{
    public const AUTO_BLOCKS_TAG = 'sulu_block_suite.auto_blocks';

    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $registeredBlocksCache = [];

    private bool $isLoadingRegisteredBlocks = false;

    /**
     * @var list<string>
     */
    private array $schemaDirectories;

    /**
     * @var list<string>
     */
    private array $presetDirectories;

    /**
     * @param string|array<array-key, string> $schemaDirectory
     * @param string|array<array-key, string> $presetDirectory
     */
    public function __construct(
        string|array $schemaDirectory,
        string|array $presetDirectory,
        private readonly ?string $projectDirectory = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
    ) {
        $this->schemaDirectories = \is_array($schemaDirectory) ? \array_values($schemaDirectory) : [$schemaDirectory];
        $this->presetDirectories = \is_array($presetDirectory) ? \array_values($presetDirectory) : [$presetDirectory];
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

        // 1. Recursively enhance any <block> field tagged with sulu_block_suite.auto_blocks
        $this->enhanceAutoBlocksInItems($formMetadata->getItems(), $formMetadata, $locale, [$key]);

        // 2. Enhance block preview tags for any block form (or container containing blocks)
        $this->enhanceBlockPreviewTags($formMetadata);

        // 3. Legacy/programmatic fallback for snippet forms constructed without tags (e.g. in unit tests)
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
     * Recursively traverses form items (sections, inline block types, and block fields)
     * to populate any <block> field carrying <tag name="sulu_block_suite.auto_blocks" .../>.
     *
     * @param array<array-key, ItemMetadata> $items
     * @param list<string> $ancestorKeys
     */
    private function enhanceAutoBlocksInItems(array $items, FormMetadata $rootForm, string $locale, array $ancestorKeys): void
    {
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $this->enhanceAutoBlocksInItems($item->getItems(), $rootForm, $locale, $ancestorKeys);

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            // First traverse inline sub-types (e.g. rows -> row_item -> columns -> column_item)
            $existingTypes = $item->getTypes();
            foreach ($existingTypes as $typeKey => $typeForm) {
                if (\is_string($typeKey) && !$typeForm->hasTag('sulu.global_block') && !\in_array($typeKey, $ancestorKeys, true)) {
                    $this->enhanceAutoBlocksInItems(
                        $typeForm->getItems(),
                        $rootForm,
                        $locale,
                        [...$ancestorKeys, $typeKey],
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
     * in the priority order: Media (2048) -> Data/Text (1024..512) -> Child Blocks (256) -> Block Props (128).
     */
    private function enhanceBlockPreviewTags(FormMetadata $formMetadata): void
    {
        $fields = $this->collectFlatFieldMetadata($formMetadata->getItems());
        if ([] === $fields) {
            return;
        }

        $hasBlockProps = false;
        foreach ($fields as $field) {
            if ('block_props' === $this->getFieldType($field)) {
                $hasBlockProps = true;
            }
            foreach ($field->getTypes() as $subTypeForm) {
                $this->enhanceBlockPreviewTags($subTypeForm);
            }
        }

        if (!$hasBlockProps && !$formMetadata->hasTag('sulu.global_block')) {
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
                $fieldType = $this->getFieldType($field);
                if ('block' === $fieldType) {
                    $this->addBlockPreviewTag($field, 50);
                } elseif ('block_props' === $fieldType) {
                    $this->addBlockPreviewTag($field, 25);
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
            if (isset($excludedFieldNames[$name])) {
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
                'block_props' => 128,
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
        $tag = new TagMetadata();
        $tag->setName('sulu.block_preview');
        $tag->setPriority($priority);
        $field->addTag($tag);
    }

    /**
     * Dynamically enhances block/snippet forms to allow any project-registered block.
     *
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

            $this->enhanceBlockPreviewTags($blockForm);

            if (!isset($existingTypes[$typeKey])) {
                $blocksProperty->addType($blockForm);
                $rootSchema->addDefinition($typeKey, $blockForm->getSchema());
                ++$addedCount;
            } else {
                ++$addedCount;
            }
        }

        // If we have registered project blocks, remove the fallback dummy 'default' type
        if ($addedCount > 0 && isset($existingTypes['default'])) {
            $blocksProperty->removeType('default');
        }

        // Ensure default type is valid
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

        // 1. Query formal Sulu MetadataProviderRegistry if available (dynamic discovery)
        $blockForms = $this->getRegisteredBlockForms($resolvedLocale);
        foreach (\array_keys($blockForms) as $blockKey) {
            if (!\in_array($blockKey, $excluded, true)) {
                $types[$blockKey] = true;
            }
        }

        // 2. Scan props schema directories
        foreach ($this->schemaDirectories as $dir) {
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

        // 3. Scan presets directories
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

        // 4. Scan host project's config/templates/blocks/*.xml if project dir provided
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
        // 1. If registered in Sulu metadata provider, resolve title directly from metadata (highest authority)
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

        // 2. Check translation keys for explicit bundle/custom translations
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
