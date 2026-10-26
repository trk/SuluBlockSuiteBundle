<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class YamlBlockPresetProvider implements BlockPresetProviderInterface, ResetInterface
{
    /**
     * @var array<string, list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>>
     */
    private array $cache = [];

    /**
     * @var array<string, array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>>
     */
    private array $directYamlCache = [];

    /**
     * @var array<string, list<SnippetInterface>>
     */
    private array $liveSnippetsByLocale = [];

    /**
     * @var array<string, list<string>>
     */
    private array $snippetUuidsByCacheKey = [];

    private ?MediaPreviewUrlResolver $mediaPreviewUrlResolverInstance = null;

    /**
     * @var list<string>
     */
    private array $yamlDirectories;

    /**
     * @param string|array<array-key, string> $yamlDirectory
     */
    public function __construct(
        string|array $yamlDirectory,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly bool $enableSnippetPresets = true,
        private readonly ?MediaManagerInterface $mediaManager = null,
        ?MediaPreviewUrlResolver $mediaPreviewUrlResolver = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly bool $multiWebspaceEnabled = true,
    ) {
        $this->yamlDirectories = \is_array($yamlDirectory) ? \array_values($yamlDirectory) : [$yamlDirectory];
        $this->mediaPreviewUrlResolverInstance = $mediaPreviewUrlResolver;
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->directYamlCache = [];
        $this->liveSnippetsByLocale = [];
        $this->snippetUuidsByCacheKey = [];
    }

    private function getMediaPreviewUrlResolver(): MediaPreviewUrlResolver
    {
        return $this->mediaPreviewUrlResolverInstance ??= new MediaPreviewUrlResolver($this->mediaManager);
    }

    /**
     * @return list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    public function getPresets(string $blockType, string $locale = 'en', ?string $webspace = null): array
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $blockType)) {
            return [];
        }

        $normalizedWebspace = $this->multiWebspaceEnabled && null !== $webspace && '' !== \trim($webspace) ? \trim($webspace) : null;
        $cacheKey = $blockType . '_' . $locale . (null !== $normalizedWebspace ? '_' . $normalizedWebspace : '');
        if (isset($this->cache[$cacheKey])) {
            foreach ($this->snippetUuidsByCacheKey[$cacheKey] ?? [] as $uuid) {
                $this->referenceStore?->add($uuid, SnippetInterface::RESOURCE_KEY);
            }

            return $this->cache[$cacheKey];
        }

        $presetsMap = $this->getDirectYamlPresets($blockType, $locale);
        $directPresetsLoader = fn (string $type, string $loc): array => $this->getDirectYamlPresets($type, $loc);

        if ('section' === $blockType) {
            foreach ($presetsMap as $sectionPresetId => $sectionPreset) {
                $presetsMap[$sectionPresetId] = PresetPayloadNormalizer::hydrateSectionChildPresets(
                    $sectionPresetId,
                    $sectionPreset,
                    $locale,
                    $directPresetsLoader,
                );
            }
        } elseif ('row' === $blockType) {
            foreach ($presetsMap as $rowPresetId => $rowPreset) {
                $presetsMap[$rowPresetId] = PresetPayloadNormalizer::hydrateRowChildPresets(
                    $rowPresetId,
                    $rowPreset,
                    $locale,
                    $directPresetsLoader,
                );
            }
        } elseif (PresetPayloadNormalizer::isExtractableChildBlockType($blockType)) {
            $presetsMap = PresetPayloadNormalizer::appendAutoExtractedChildPresets(
                $blockType,
                $locale,
                $presetsMap,
                $directPresetsLoader,
            );
        }

        /** @var list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presets */
        $presets = \array_values($presetsMap);
        $matchedUuids = [];

        if ($this->enableSnippetPresets && null !== $this->snippetRepository) {
            try {
                if (!isset($this->liveSnippetsByLocale[$locale])) {
                    /** @var iterable<SnippetInterface> $fetched */
                    $fetched = $this->snippetRepository->findBy(
                        [
                            'templateKeys' => [TemplateKeys::BLOCK_PRESET],
                            'locale' => $locale,
                            'stage' => DimensionContentInterface::STAGE_LIVE,
                        ],
                        ['title' => 'asc'],
                        [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
                    );

                    $list = [];
                    foreach ($fetched as $item) {
                        $list[] = $item;
                    }
                    $this->liveSnippetsByLocale[$locale] = $list;
                }

                foreach ($this->liveSnippetsByLocale[$locale] as $snippet) {
                    $content = SnippetScopeResolver::findDimensionContent(
                        $snippet,
                        TemplateKeys::BLOCK_PRESET,
                        $locale,
                        DimensionContentInterface::STAGE_LIVE,
                    );
                    if (null === $content) {
                        continue;
                    }

                    $templateData = SnippetScopeResolver::getMergedTemplateData(
                        $snippet,
                        $content,
                        DimensionContentInterface::STAGE_LIVE,
                    );
                    $presetWebspace = SnippetScopeResolver::extractWebspace($templateData, '');

                    if (!SnippetScopeResolver::matchesWebspace($presetWebspace, $normalizedWebspace, $this->multiWebspaceEnabled)) {
                        continue;
                    }

                    $blockEntry = null;
                    if (isset($templateData['block']) && \is_array($templateData['block']) && isset($templateData['block'][0]) && \is_array($templateData['block'][0])) {
                        $blockEntry = $templateData['block'][0];
                    }

                    $configuredType = null;
                    if (null !== $blockEntry && isset($blockEntry['type']) && \is_string($blockEntry['type'])) {
                        $configuredType = $blockEntry['type'];
                    } elseif (isset($templateData['target_block']) && \is_string($templateData['target_block'])) {
                        $configuredType = $templateData['target_block'];
                    }

                    if ($configuredType === $blockType) {
                        $uuid = $snippet->getUuid();
                        $title = $content->getTitle() ?? $uuid;
                        if ($this->isTestPreset($uuid, $title)) {
                            continue;
                        }

                        if ('' !== $uuid) {
                            $matchedUuids[] = $uuid;
                            $this->referenceStore?->add($uuid, SnippetInterface::RESOURCE_KEY);
                        }

                        /** @var array<string, mixed> $props */
                        $props = [];
                        $rawProps = null;
                        if (null !== $blockEntry && isset($blockEntry['props']) && \is_array($blockEntry['props'])) {
                            $rawProps = $blockEntry['props'];
                        } elseif (isset($templateData['props']) && \is_array($templateData['props'])) {
                            $rawProps = $templateData['props'];
                        }
                        if (\is_array($rawProps)) {
                            foreach ($rawProps as $k => $v) {
                                $props[(string) $k] = $v;
                            }
                        }

                        /** @var array<string, mixed> $values */
                        $values = [];
                        if (null !== $blockEntry) {
                            foreach ($blockEntry as $k => $v) {
                                $values[(string) $k] = $v;
                            }
                        }

                        $description = isset($templateData['description']) && \is_string($templateData['description']) ? $templateData['description'] : null;

                        $previewImage = $this->getMediaPreviewUrlResolver()->resolve($templateData['preview_image'] ?? null, $locale);

                        $presets[] = [
                            'id' => 'snippet_' . $uuid,
                            'title' => $title,
                            'description' => $description,
                            'preview_image' => $previewImage,
                            'props' => $props,
                            'values' => $values,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Gracefully fallback if snippet tables are not initialized
            }
        }

        $this->snippetUuidsByCacheKey[$cacheKey] = $matchedUuids;
        $this->cache[$cacheKey] = $presets;

        return $presets;
    }

    /**
     * @return array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    private function getDirectYamlPresets(string $blockType, string $locale): array
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $blockType)) {
            return [];
        }

        $cacheKey = $blockType . '_' . $locale;
        if (isset($this->directYamlCache[$cacheKey])) {
            return $this->directYamlCache[$cacheKey];
        }

        /** @var array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presetsMap */
        $presetsMap = [];

        foreach ($this->yamlDirectories as $dir) {
            $realBase = \realpath($dir);
            if (false === $realBase || !\is_dir($realBase)) {
                continue;
            }

            $basePath = \rtrim($realBase, '/');
            $candidateSingleFiles = [
                $basePath . '/' . $blockType . '.yaml',
                $basePath . '/' . $blockType . '.yml',
                $basePath . '/presets/' . $blockType . '.yaml',
                $basePath . '/presets/' . $blockType . '.yml',
            ];

            $filesToLoad = [];
            foreach ($candidateSingleFiles as $singleFile) {
                if (\is_file($singleFile)) {
                    $realFile = \realpath($singleFile);
                    if (false !== $realFile && \str_starts_with($realFile, $realBase . \DIRECTORY_SEPARATOR)) {
                        $filesToLoad[] = $realFile;
                        break;
                    }
                }
            }

            foreach ([$basePath . '/' . $blockType, $basePath . '/presets/' . $blockType] as $subDir) {
                $realSubDir = \realpath($subDir);
                if (false !== $realSubDir && \is_dir($realSubDir) && \str_starts_with($realSubDir, $realBase . \DIRECTORY_SEPARATOR)) {
                    $subFiles = \array_merge(
                        \glob($realSubDir . '/*.yaml') ?: [],
                        \glob($realSubDir . '/*.yml') ?: [],
                    );
                    \sort($subFiles);
                    foreach ($subFiles as $subFile) {
                        if (\is_file($subFile)) {
                            $realFile = \realpath($subFile);
                            if (false !== $realFile && \str_starts_with($realFile, $realBase . \DIRECTORY_SEPARATOR)) {
                                $filesToLoad[] = $realFile;
                            }
                        }
                    }
                }
            }

            foreach ($filesToLoad as $filePath) {
                try {
                    $parsed = Yaml::parseFile($filePath);
                    if (\is_array($parsed)) {
                        /** @var mixed $rawPresets */
                        $rawPresets = null;
                        if (isset($parsed['presets']) && \is_array($parsed['presets'])) {
                            $rawPresets = $parsed['presets'];
                        } elseif (isset($parsed[$blockType]) && \is_array($parsed[$blockType]) && isset($parsed[$blockType]['presets']) && \is_array($parsed[$blockType]['presets'])) {
                            $rawPresets = $parsed[$blockType]['presets'];
                        }

                        if (\is_array($rawPresets)) {
                            foreach ($rawPresets as $presetKey => $presetConfig) {
                                if (!\is_array($presetConfig)) {
                                    continue;
                                }

                                $presetId = \is_string($presetKey)
                                    ? $presetKey
                                    : (isset($presetConfig['id']) && \is_string($presetConfig['id']) ? $presetConfig['id'] : null);

                                if (null === $presetId || '' === $presetId) {
                                    continue;
                                }

                                $rawTitle = $presetConfig['title'] ?? ($presetConfig['label'] ?? ($presetConfig['name'] ?? null));
                                $title = $this->resolveLocalizedText($rawTitle, $locale, $presetId) ?? $presetId;

                                if ($this->isTestPreset($presetId, $title)) {
                                    continue;
                                }

                                $description = $this->resolveLocalizedText($presetConfig['description'] ?? null, $locale, null);
                                $previewImage = isset($presetConfig['preview_image']) && \is_string($presetConfig['preview_image'])
                                    ? $presetConfig['preview_image']
                                    : null;

                                /** @var array<string, mixed> $props */
                                $props = [];
                                if (isset($presetConfig['props']) && \is_array($presetConfig['props'])) {
                                    foreach ($presetConfig['props'] as $k => $v) {
                                        $props[(string) $k] = $v;
                                    }
                                }

                                /** @var array<string, mixed> $values */
                                $values = [];
                                if (isset($presetConfig['values']) && \is_array($presetConfig['values'])) {
                                    foreach ($presetConfig['values'] as $k => $v) {
                                        $values[(string) $k] = $v;
                                    }
                                }

                                [$props, $values] = PresetPayloadNormalizer::normalizeBlockPropsAndValues($blockType, $props, $values);

                                $presetsMap[$presetId] = [
                                    'id' => $presetId,
                                    'title' => $title,
                                    'description' => $description,
                                    'preview_image' => $previewImage,
                                    'props' => $props,
                                    'values' => $values,
                                ];
                            }
                        }
                    }
                } catch (ParseException) {
                    // Fallback gracefully on YAML parse error
                }
            }
        }

        $this->directYamlCache[$cacheKey] = $presetsMap;

        return $presetsMap;
    }

    private function resolveLocalizedText(mixed $data, string $locale, ?string $fallback): ?string
    {
        if (null === $data) {
            return $fallback;
        }

        if (\is_string($data)) {
            if (null !== $this->translator) {
                $translated = $this->translator->trans($data, [], 'admin', $locale);
                if ($translated !== $data) {
                    return $translated;
                }

                $translatedDefault = $this->translator->trans($data, [], null, $locale);
                if ($translatedDefault !== $data) {
                    return $translatedDefault;
                }
            }

            return $data;
        }

        if (\is_array($data)) {
            if (isset($data[$locale]) && \is_string($data[$locale])) {
                return $data[$locale];
            }

            $shortLocale = \explode('_', $locale)[0];
            if (isset($data[$shortLocale]) && \is_string($data[$shortLocale])) {
                return $data[$shortLocale];
            }

            if (isset($data['en']) && \is_string($data['en'])) {
                return $data['en'];
            }

            $first = \reset($data);
            if (\is_string($first)) {
                return $first;
            }
        }

        return $fallback;
    }

    private function isTestPreset(string $id, string $title): bool
    {
        return 1 === \preg_match('/^test(\b|_|-|\s|$)/i', \trim($id))
            || 1 === \preg_match('/^test(\b|_|-|\s|$)/i', \trim($title));
    }

    public function clearCache(?string $blockType = null, ?string $locale = null): void
    {
        if (null !== $blockType && null !== $locale) {
            $cacheKey = $blockType . '_' . $locale;
            unset(
                $this->cache[$cacheKey],
                $this->directYamlCache[$cacheKey],
                $this->snippetUuidsByCacheKey[$cacheKey],
                $this->liveSnippetsByLocale[$locale],
            );
            $prefix = $cacheKey . '_';
            foreach (\array_keys($this->cache) as $existingKey) {
                if (\str_starts_with($existingKey, $prefix)) {
                    unset($this->cache[$existingKey], $this->snippetUuidsByCacheKey[$existingKey]);
                }
            }
        } else {
            $this->reset();
        }
    }
}
