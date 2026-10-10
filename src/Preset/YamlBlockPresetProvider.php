<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class YamlBlockPresetProvider implements BlockPresetProviderInterface
{
    /**
     * @var array<string, list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>>
     */
    private array $cache = [];

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
    ) {
        $this->yamlDirectories = \is_array($yamlDirectory) ? \array_values($yamlDirectory) : [$yamlDirectory];
        $this->mediaPreviewUrlResolverInstance = $mediaPreviewUrlResolver;
    }

    private function getMediaPreviewUrlResolver(): MediaPreviewUrlResolver
    {
        return $this->mediaPreviewUrlResolverInstance ??= new MediaPreviewUrlResolver($this->mediaManager);
    }

    /**
     * @return list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    public function getPresets(string $blockType, string $locale = 'en'): array
    {
        $cacheKey = $blockType . '_' . $locale;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        /** @var array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presetsMap */
        $presetsMap = [];

        foreach ($this->yamlDirectories as $dir) {
            $basePath = \rtrim($dir, '/');
            $candidateFiles = [
                $basePath . '/' . $blockType . '.yaml',
                $basePath . '/' . $blockType . '.yml',
                $basePath . '/presets/' . $blockType . '.yaml',
                $basePath . '/presets/' . $blockType . '.yml',
            ];

            foreach ($candidateFiles as $filePath) {
                if (\is_file($filePath)) {
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
                            foreach ($rawPresets as $presetId => $presetConfig) {
                                if (!\is_string($presetId) || !\is_array($presetConfig)) {
                                    continue;
                                }

                                $title = $this->resolveLocalizedText($presetConfig['title'] ?? null, $locale, $presetId);
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

                                $presetsMap[$presetId] = [
                                    'id' => $presetId,
                                    'title' => $title ?? $presetId,
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
                break;
            }
        }
    }

        /** @var list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presets */
        $presets = \array_values($presetsMap);

        if ($this->enableSnippetPresets && null !== $this->snippetRepository) {
            try {
                $snippets = $this->snippetRepository->findBy(
                    [
                        'templateKeys' => ['block_preset'],
                        'locale' => $locale,
                    ],
                    ['title' => 'asc'],
                    [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
                );

                foreach ($snippets as $snippet) {
                    foreach ($snippet->getDimensionContents() as $content) {
                        if ('block_preset' === $content->getTemplateKey()) {
                            $templateData = $content->getTemplateData();

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

                                $title = $content->getTitle() ?? $snippet->getUuid();
                                $description = isset($templateData['description']) && \is_string($templateData['description']) ? $templateData['description'] : null;

                                $previewImage = $this->getMediaPreviewUrlResolver()->resolve($templateData['preview_image'] ?? null, $locale);

                                $presets[] = [
                                    'id' => 'snippet_' . $snippet->getUuid(),
                                    'title' => $title,
                                    'description' => $description,
                                    'preview_image' => $previewImage,
                                    'props' => $props,
                                    'values' => $values,
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Gracefully fallback if snippet tables are not initialized
            }
        }

        $this->cache[$cacheKey] = $presets;

        return $presets;
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

    public function clearCache(?string $blockType = null, ?string $locale = null): void
    {
        if (null !== $blockType && null !== $locale) {
            unset($this->cache[$blockType . '_' . $locale]);
        } else {
            $this->cache = [];
        }
    }
}
