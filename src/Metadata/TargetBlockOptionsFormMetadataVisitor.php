<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TargetBlockOptionsFormMetadataVisitor implements FormMetadataVisitorInterface
{
    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $registeredBlocksCache = [];

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

    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        $key = $formMetadata->getKey();

        if ('reusable_block' === $key || 'page_layout' === $key) {
            $this->enhanceBlockTypes($formMetadata, $locale, 'blocks');

            return;
        }

        if ('block_preset' !== $key) {
            return;
        }

        $this->enhanceBlockTypes($formMetadata, $locale, 'block');

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
     * Dynamically enhances block/snippet forms to allow any project-registered block.
     */
    private function enhanceBlockTypes(FormMetadata $formMetadata, string $locale, string $propertyName = 'blocks'): void
    {
        $items = $formMetadata->getItems();
        if (!isset($items[$propertyName]) || !$items[$propertyName] instanceof FieldMetadata) {
            return;
        }

        /** @var FieldMetadata $blocksProperty */
        $blocksProperty = $items[$propertyName];
        $blockForms = $this->getRegisteredBlockForms($locale);
        if ([] === $blockForms) {
            return;
        }

        $rootSchema = $formMetadata->getSchema();
        $existingTypes = $blocksProperty->getTypes();

        $addedCount = 0;
        foreach ($blockForms as $typeKey => $blockForm) {
            if ('reusable' === $typeKey || 'default' === $typeKey || '' === $typeKey) {
                continue;
            }

            if (!isset($existingTypes[$typeKey])) {
                $blocksProperty->addType($blockForm);
                $rootSchema->addDefinition($typeKey, $blockForm->getSchema());
                ++$addedCount;
            }
        }

        // If we have registered project blocks, remove the fallback dummy 'default' type
        if ($addedCount > 0 && isset($existingTypes['default'])) {
            $blocksProperty->removeType('default');
        }

        // Ensure default type is valid
        $updatedTypes = $blocksProperty->getTypes();
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

        // 1. Query formal Sulu MetadataProviderRegistry if available (dynamic discovery)
        $blockForms = $this->getRegisteredBlockForms($resolvedLocale);
        foreach (\array_keys($blockForms) as $blockKey) {
            if ('reusable' !== $blockKey && 'default' !== $blockKey && '' !== $blockKey) {
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
                        if ('common' !== $basename && 'default' !== $basename && '' !== $basename) {
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
                        if ('default' !== $basename && '' !== $basename) {
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
                        if ('' !== $basename) {
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
        }

        return $this->registeredBlocksCache[$locale] = [];
    }
}
