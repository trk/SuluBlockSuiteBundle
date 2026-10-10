<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Schema\YamlBlockPropSchemaProvider;

final class YamlBlockPropSchemaProviderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/sulu_block_props_test_' . \uniqid();
        \mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testGetSchemaReturnsEmptyTabsWhenBlockNotFound(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $provider = new YamlBlockPropSchemaProvider($this->tempDir, $translator);

        $schema = $provider->getSchema('unknown_block', 'en');

        $this->assertSame(['tabs' => []], $schema);
    }

    public function testGetSchemaReturnsFieldsAndMergesCommonTabs(): void
    {
        $commonYaml = <<<YAML
tabs:
  spacing:
    title: "Spacing & Layout"
    fields:
      margin_top:
        type: select
        label: "Margin Top"
        options:
          - { value: 'none', label: 'None' }
YAML;
        \file_put_contents($this->tempDir . '/common.yaml', $commonYaml);

        $buttonYaml = <<<YAML
tabs:
  appearance:
    title:
      en: "Appearance"
      tr: "Görünüm"
    fields:
      variant:
        type: select
        label:
          en: "Variant"
          tr: "Varyant"
        options:
          - value: 'solid'
            label:
              en: "Solid"
              tr: "Dolu"
YAML;
        \file_put_contents($this->tempDir . '/button.yaml', $buttonYaml);

        $translator = $this->createStub(TranslatorInterface::class);
        $provider = new YamlBlockPropSchemaProvider($this->tempDir, $translator);

        $schema = $provider->getSchema('button', 'tr');

        $this->assertCount(2, $schema['tabs']);
        $tabIds = \array_column($schema['tabs'], 'id');
        $this->assertContains('appearance', $tabIds);
        $this->assertContains('spacing', $tabIds);

        $appearanceTab = $schema['tabs'][0];
        $this->assertSame('Görünüm', $appearanceTab['title']);

        $variantField = $appearanceTab['fields'][0];
        $this->assertSame('Varyant', $variantField['label']);
        $this->assertArrayHasKey('options', $variantField);
        $this->assertSame('Dolu', $variantField['options'][0]['label']);

        // Check fallback to English when requested in English
        $schemaEn = $provider->getSchema('button', 'en');
        $this->assertSame('Appearance', $schemaEn['tabs'][0]['title']);
        $this->assertSame('Variant', $schemaEn['tabs'][0]['fields'][0]['label']);
        $this->assertArrayHasKey('options', $schemaEn['tabs'][0]['fields'][0]);
        $this->assertSame('Solid', $schemaEn['tabs'][0]['fields'][0]['options'][0]['label']);
    }

    public function testCascadesMultipleDirectoriesAndMergesCommon(): void
    {
        $secondDir = \sys_get_temp_dir() . '/sulu_block_props_second_' . \uniqid();
        \mkdir($secondDir, 0777, true);

        try {
            // First dir has base common
            \file_put_contents($this->tempDir . '/common.yaml', "tabs:\n  layout:\n    title: 'Layout Base'\n    fields: []");
            // Second dir has custom card schema and project-level common tab
            \file_put_contents($secondDir . '/card.yaml', "tabs:\n  card_style:\n    title: 'Card Style'\n    fields: []");
            \file_put_contents($secondDir . '/common.yaml', "tabs:\n  spacing:\n    title: 'Spacing Project'\n    fields: []");

            $provider = new YamlBlockPropSchemaProvider([$this->tempDir, $secondDir]);
            $schema = $provider->getSchema('card', 'en');

            $this->assertCount(3, $schema['tabs']);
            $tabIds = \array_column($schema['tabs'], 'id');
            $this->assertSame(['card_style', 'layout', 'spacing'], $tabIds);
        } finally {
            $this->removeDirectory($secondDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \scandir($dir);
        if (false === $files) {
            return;
        }

        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }

            $path = $dir . '/' . $file;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                \unlink($path);
            }
        }

        \rmdir($dir);
    }
}
