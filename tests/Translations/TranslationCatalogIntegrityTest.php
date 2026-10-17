<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Translations;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceRegistry;

final class TranslationCatalogIntegrityTest extends TestCase
{
    private const LOCALES = ['en', 'tr', 'de', 'es', 'fr', 'ru'];

    public function testCoreTranslationCatalogsHaveIdenticalKeysAndEveryKeyIsReferencedInCore(): void
    {
        $coreRoot = \dirname(__DIR__, 2);
        $translationsDir = $coreRoot . '/translations';

        /** @var array<string, array<string, string>> $catalogs */
        $catalogs = [];
        foreach (self::LOCALES as $locale) {
            $file = $translationsDir . '/admin.' . $locale . '.json';
            $this->assertFileExists($file);

            $raw = \file_get_contents($file);
            $this->assertIsString($raw);

            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);

            /** @var array<string, string> $typedCatalog */
            $typedCatalog = [];
            foreach ($decoded as $key => $value) {
                $this->assertIsString($key);
                $this->assertIsString($value);
                $this->assertNotSame('', \trim($value), \sprintf('Empty translation value for key "%s" in locale "%s".', $key, $locale));
                $typedCatalog[$key] = $value;
            }

            $catalogs[$locale] = $typedCatalog;
        }

        $enKeys = \array_keys($catalogs['en']);
        \sort($enKeys);
        $this->assertNotEmpty($enKeys);

        foreach (self::LOCALES as $locale) {
            $localeKeys = \array_keys($catalogs[$locale]);
            \sort($localeKeys);
            $this->assertSame(
                $enKeys,
                $localeKeys,
                \sprintf('Core admin.%s.json key set does not match admin.en.json.', $locale),
            );
        }

        $corpus = $this->collectCoreSourceCorpus($coreRoot);
        $unreferencedKeys = [];
        foreach ($enKeys as $key) {
            if (!\str_contains($corpus, $key)) {
                $unreferencedKeys[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unreferencedKeys,
            'Every key in packages/sulu-block-suite-bundle/translations/admin.*.json must be referenced in Core (src/, config/, docs/, or assets/admin/). Unreferenced: ' . \implode(', ', $unreferencedKeys),
        );
    }

    public function testPrelineTranslationCatalogsHaveIdenticalKeysAndNoCoreNamespaceLeakage(): void
    {
        $prelineTranslationsDir = \dirname(__DIR__, 3) . '/sulu-preline-blocks-bundle/translations';
        if (!\is_dir($prelineTranslationsDir)) {
            $this->markTestSkipped('sulu-preline-blocks-bundle translations directory not present.');
        }

        /** @var array<string, list<string>> $keysByLocale */
        $keysByLocale = [];
        foreach (self::LOCALES as $locale) {
            $file = $prelineTranslationsDir . '/admin.' . $locale . '.json';
            $this->assertFileExists($file);

            $raw = \file_get_contents($file);
            $this->assertIsString($raw);

            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);

            $keys = [];
            foreach (\array_keys($decoded) as $key) {
                $this->assertIsString($key);
                $this->assertStringStartsNotWith(
                    'sulu_block_suite.block_',
                    $key,
                    \sprintf('Preline admin.%s.json must not contain dead sulu_block_suite.block_* key "%s".', $locale, $key),
                );
                $this->assertStringStartsNotWith(
                    'sulu_block_suite.opt_',
                    $key,
                    \sprintf('Preline admin.%s.json must not contain sulu_block_suite.opt_* key "%s".', $locale, $key),
                );
                $keys[] = $key;
            }
            \sort($keys);
            $keysByLocale[$locale] = $keys;
        }

        $enKeys = $keysByLocale['en'];
        foreach (self::LOCALES as $locale) {
            $this->assertSame(
                $enKeys,
                $keysByLocale[$locale],
                \sprintf('Preline admin.%s.json key set does not match admin.en.json.', $locale),
            );
        }
    }

    public function testDynamicContentProvidersResolveLabelsViaTranslatorCatalogAcrossAllSixLocales(): void
    {
        $coreRoot = \dirname(__DIR__, 2);
        /** @var array<string, array<string, string>> $catalogs */
        $catalogs = [];
        foreach (self::LOCALES as $locale) {
            $raw = (string) \file_get_contents($coreRoot . '/translations/admin.' . $locale . '.json');
            /** @var array<string, string> $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            $catalogs[$locale] = $decoded;
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null) use ($catalogs): string {
                if ('admin' !== $domain) {
                    return $id;
                }
                $resolvedLocale = $locale ?? 'en';

                return $catalogs[$resolvedLocale][$id] ?? $id;
            },
        );

        $registry = new DynamicContentSourceRegistry(translator: $translator);

        $trDescriptors = $registry->getSourceDescriptors('tr');
        $trLabelsByKey = [];
        foreach ($trDescriptors as $descriptor) {
            $trLabelsByKey[$descriptor['key']] = $descriptor['label'];
        }

        $this->assertSame('Yok', $trLabelsByKey['none'] ?? null);
        $this->assertSame('Mevcut Sayfa', $trLabelsByKey['current_page'] ?? null);
        $this->assertSame('Makaleler', $trLabelsByKey['sulu_articles'] ?? null);
        $this->assertSame('Kategoriler', $trLabelsByKey['sulu_categories'] ?? null);
        $this->assertSame('Medya Koleksiyonu', $trLabelsByKey['sulu_media_collection'] ?? null);

        $this->assertSame('Başlık', AbstractDynamicContentSourceProvider::translateSourceFieldLabel('title', 'tr', $translator));
        $this->assertSame('Titel', AbstractDynamicContentSourceProvider::translateSourceFieldLabel('title', 'de', $translator));
        $this->assertSame('Заголовок', AbstractDynamicContentSourceProvider::translateSourceFieldLabel('title', 'ru', $translator));
        $this->assertSame('Título', AbstractDynamicContentSourceProvider::translateSourceFieldLabel('title', 'es', $translator));
        $this->assertSame('Titre', AbstractDynamicContentSourceProvider::translateSourceFieldLabel('title', 'fr', $translator));
    }

    private function collectCoreSourceCorpus(string $coreRoot): string
    {
        $scanDirs = [
            $coreRoot . '/src',
            $coreRoot . '/config',
            $coreRoot . '/docs',
            $coreRoot . '/assets/admin',
        ];

        $chunks = [];
        foreach ($scanDirs as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }
                $ext = $fileInfo->getExtension();
                if (!\in_array($ext, ['php', 'js', 'xml', 'yaml', 'md', 'twig'], true)) {
                    continue;
                }
                $contents = \file_get_contents($fileInfo->getPathname());
                if (\is_string($contents)) {
                    $chunks[] = $contents;
                }
            }
        }

        return \implode("\n", $chunks);
    }
}
