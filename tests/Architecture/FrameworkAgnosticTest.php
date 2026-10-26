<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class FrameworkAgnosticTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const SCAN_DIRECTORIES = [
        'src',
        'config',
        'templates',
        'assets/admin',
        'translations',
    ];

    /**
     * @var list<string>
     */
    private const PRELINE_BLOCK_KEYS = [
        'accordion',
        'alert',
        'breadcrumbs',
        'countdown',
        'description_list',
        'divider',
        'gallery',
        'headline',
        'overlay',
        'overlay_slider',
        'panel_slider',
        'popover',
        'quotation',
        'scroll_to',
        'slideshow',
        'subnav',
        'switcher',
        'row_item',
        'column_item',
        'accordion_item',
        'popover_item',
        'slide_item',
        'panel_item',
        'stat_item',
        'step_item',
        'timeline_item',
        'social_item',
    ];

    /**
     * Subset of PRELINE_BLOCK_KEYS that collide with generic Sulu/Admin vocabulary:
     * - 'headline': Sulu page/article/category dynamic content source field name & preview text property
     * - 'gallery': Core's PresetGallery modal UI component
     * - 'overlay': Sulu Admin's `sulu-admin-bundle/components/Overlay` modal component
     *
     * @var array<string, list<string>>
     */
    private const GENERIC_WORD_ALLOWED_FILES = [
        'headline' => [
            'src/DynamicContent/AbstractDynamicContentSourceProvider.php',
            'src/DynamicContent/DynamicContentHydrator.php',
            'src/DynamicContent/SuluContentDimensionExtractor.php',
            'src/DynamicContent/SuluPageSourceResolver.php',
            'src/DynamicContent/Provider/SmartContentSourceProviderAdapter.php',
            'src/DynamicContent/Provider/SuluArticleSourceProvider.php',
            'src/DynamicContent/Provider/SuluCategorySourceProvider.php',
            'src/DynamicContent/Provider/SuluMediaCollectionSourceProvider.php',
            'src/DynamicContent/Provider/SuluPageSourceProvider.php',
            'src/DynamicContent/Provider/SuluSearchSourceProvider.php',
            'assets/admin/components/PresetSelection/BlockCollectionBlockPreviewTransformer.js',
            'translations/admin.de.json',
            'translations/admin.en.json',
            'translations/admin.es.json',
            'translations/admin.fr.json',
            'translations/admin.ru.json',
            'translations/admin.tr.json',
        ],
        'gallery' => [
            'assets/admin/components/PresetSelection/Preset.js',
            'assets/admin/components/PresetSelection/PresetGallery.js',
            'assets/admin/components/PresetSelection/PresetSelection.js',
            'assets/admin/components/PresetSelection/presetGallery.scss',
            'translations/admin.de.json',
            'translations/admin.en.json',
            'translations/admin.es.json',
            'translations/admin.fr.json',
            'translations/admin.ru.json',
            'translations/admin.tr.json',
        ],
        'overlay' => [
            'assets/admin/components/PresetSelection/PresetGallery.js',
            'assets/admin/components/SingleReusableBlockSelection/SingleReusableBlockSelection.js',
            'assets/admin/components/SingleReusableBlockSelection/singleReusableBlockSelection.scss',
        ],
    ];

    /**
     * @var list<string>
     */
    private const PRELINE_STYLING_FIELDS = [
        'margin_top',
        'margin_bottom',
        'padding_top',
        'padding_bottom',
        'maxwidth',
        'max_width_breakpoint',
        'block_align',
        'block_align_breakpoint',
        'block_align_fallback',
        'text_align',
        'text_align_breakpoint',
        'text_align_fallback',
        'container_padding_remove',
        'item_animation',
        'blend',
        'mix_blend_mode',
        'parallax',
        'parallax_start',
        'parallax_end',
        'animation',
        'animation_delay',
        'animation_repeat',
        'hover_image',
        'hover_video',
        'location_query',
        '_in_column',
    ];

    /**
     * @var list<string>
     */
    private const PROJECT_IMAGE_FORMATS = [
        'col-sm',
        'col-md',
        'col-lg',
        'col-xl',
        'col-2xl',
    ];

    /**
     * @var list<string>
     */
    private const PRELINE_BUNDLE_REFERENCES = [
        'Totoglu\\SuluPrelineBlocksBundle',
        'SuluPrelineBlocksBundle',
        '@SuluPrelineBlocks',
        'sulu_preline_blocks',
    ];

    public function testBlockTemplatesDirectoryContainsOnlyReusableAndNoSnippetsOrGeneralFormsExist(): void
    {
        $bundleRoot = $this->getBundleRoot();
        $blocksDir = $bundleRoot . '/config/templates/blocks';

        self::assertDirectoryExists($blocksDir);

        $files = \glob($blocksDir . '/*.xml');
        self::assertIsArray($files);

        $basenames = \array_map(static fn (string $path): string => \basename($path), $files);
        \sort($basenames);

        self::assertSame(['reusable.xml'], $basenames, 'Core config/templates/blocks must only define reusable.xml.');
        self::assertDirectoryDoesNotExist($bundleRoot . '/config/templates/snippets');
        self::assertDirectoryDoesNotExist($bundleRoot . '/config/templates/block-items');
        self::assertFileDoesNotExist($bundleRoot . '/config/forms/block_suite_general.xml');
        self::assertDirectoryDoesNotExist($bundleRoot . '/config/forms/fields');
    }

    public function testNoPrelineBundleReferencesAppearInCore(): void
    {
        $violations = [];

        foreach ($this->collectBundleFiles([...self::SCAN_DIRECTORIES, 'docs/examples']) as $relativePath => $content) {
            foreach (self::PRELINE_BUNDLE_REFERENCES as $reference) {
                if (\str_contains($content, $reference)) {
                    $violations[] = \sprintf('%s contains forbidden reference "%s"', $relativePath, $reference);
                }
            }
        }

        self::assertSame([], $violations, "Preline bundle references found in Core:\n" . \implode("\n", $violations));
    }

    public function testNoPrelineStylingFieldNamesAppearInCore(): void
    {
        $violations = [];
        $pattern = '/(?<![a-zA-Z0-9_-])(' . \implode('|', \array_map(static fn (string $f): string => \preg_quote($f, '/'), self::PRELINE_STYLING_FIELDS)) . ')\b/';

        foreach ($this->collectBundleFiles(self::SCAN_DIRECTORIES) as $relativePath => $content) {
            $scannableContent = \str_ends_with($relativePath, '.scss')
                ? (string) \preg_replace('/^\s*animation\s*:/m', '', $content)
                : $content;

            if (\preg_match_all($pattern, $scannableContent, $matches) >= 1) {
                $found = \array_unique($matches[1]);
                $violations[] = \sprintf('%s contains Preline styling field(s): %s', $relativePath, \implode(', ', $found));
            }
        }

        self::assertSame([], $violations, "Preline styling field names found in Core:\n" . \implode("\n", $violations));
    }

    public function testNoProjectSpecificImageFormatNamesAppearInCore(): void
    {
        $violations = [];
        $pattern = '/(?<![a-zA-Z0-9_-])(' . \implode('|', \array_map(static fn (string $f): string => \preg_quote($f, '/'), self::PROJECT_IMAGE_FORMATS)) . ')(?![a-zA-Z0-9_-])/';

        foreach ($this->collectBundleFiles(self::SCAN_DIRECTORIES) as $relativePath => $content) {
            if (\preg_match_all($pattern, $content, $matches) >= 1) {
                $found = \array_unique($matches[1]);
                $violations[] = \sprintf('%s contains project-specific image format(s): %s', $relativePath, \implode(', ', $found));
            }
        }

        self::assertSame([], $violations, "Project-specific image format names found in Core:\n" . \implode("\n", $violations));
    }

    public function testNoPrelineBlockKeysAppearInCore(): void
    {
        $violations = [];

        foreach ($this->collectBundleFiles(self::SCAN_DIRECTORIES) as $relativePath => $content) {
            foreach (self::PRELINE_BLOCK_KEYS as $blockKey) {
                if (1 === \preg_match('/<key>\s*' . \preg_quote($blockKey, '/') . '\s*<\/key>|<type\s+ref="' . \preg_quote($blockKey, '/') . '"|default-type="' . \preg_quote($blockKey, '/') . '"|sulu_block_suite\.block_' . \preg_quote($blockKey, '/') . '\b/', $content)) {
                    $violations[] = \sprintf('%s references Preline block key "%s"', $relativePath, $blockKey);
                    continue;
                }

                if (1 !== \preg_match('/\b' . \preg_quote($blockKey, '/') . '\b/i', $content)) {
                    continue;
                }

                $allowedFiles = self::GENERIC_WORD_ALLOWED_FILES[$blockKey] ?? [];
                if (\in_array($relativePath, $allowedFiles, true)) {
                    continue;
                }

                $violations[] = \sprintf('%s contains Preline block token "%s"', $relativePath, $blockKey);
            }
        }

        self::assertSame([], $violations, "Preline block keys found in Core:\n" . \implode("\n", $violations));
    }

    public function testNoHardcodedLocaleArraysOrCssFrameworkClassMapsAppearInSrc(): void
    {
        $violations = [];

        $localeArrayPattern = '/\[\s*[\'"]en[\'"]\s*,\s*[\'"]tr[\'"]\s*,\s*[\'"]de[\'"]|\[\s*[\'"]en[\'"]\s*=>\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]tr[\'"]\s*=>/';
        $cssFrameworkPattern = '/\b(PrelineClassMap|data-hs-[a-z-]+|hs-accordion|hs-dropdown|hs-overlay|hs-carousel|max-w-screen-(?:sm|md|lg|xl|2xl)|grid-cols-\d+|dark:bg-[a-z0-9-]+|dark:text-[a-z0-9-]+)\b/';

        foreach ($this->collectBundleFiles(['src']) as $relativePath => $content) {
            if (1 === \preg_match($localeArrayPattern, $content, $match)) {
                $violations[] = \sprintf('%s contains a hardcoded multi-locale array: %s', $relativePath, $match[0]);
            }

            if (1 === \preg_match($cssFrameworkPattern, $content, $match)) {
                $violations[] = \sprintf('%s contains CSS framework class/token: %s', $relativePath, $match[0]);
            }
        }

        self::assertSame([], $violations, "Hardcoded locale arrays or CSS framework class maps found in Core src/:\n" . \implode("\n", $violations));
    }

    private function getBundleRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $directories
     *
     * @return array<string, string> Map of relative file path => file contents
     */
    private function collectBundleFiles(array $directories): array
    {
        $bundleRoot = $this->getBundleRoot();
        $files = [];

        foreach ($directories as $directory) {
            $fullDir = $bundleRoot . '/' . $directory;
            if (!\is_dir($fullDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullDir, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $pathname = $fileInfo->getPathname();
                if (\str_contains($pathname, '/node_modules/')) {
                    continue;
                }

                $relativePath = \str_replace('\\', '/', \substr($pathname, \strlen($bundleRoot) + 1));
                $contents = \file_get_contents($pathname);
                if (false !== $contents) {
                    $files[$relativePath] = $contents;
                }
            }
        }

        \ksort($files);

        return $files;
    }
}
