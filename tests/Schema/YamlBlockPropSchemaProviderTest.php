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
        $variantFieldEn = $schemaEn['tabs'][0]['fields'][0];
        $this->assertSame('Variant', $variantFieldEn['label']);
        $this->assertArrayHasKey('options', $variantFieldEn);
        $this->assertSame('Solid', $variantFieldEn['options'][0]['label']);
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

    public function testDeepCascadeMergesTabsAndFieldsAndSupportsYmlExtension(): void
    {
        $secondDir = \sys_get_temp_dir() . '/sulu_block_props_cascade_' . \uniqid();
        \mkdir($secondDir, 0777, true);

        try {
            // Base dir (e.g. bundle) defines alert with appearance tab having variant & dismissible
            $baseAlertYaml = <<<YAML
tabs:
  appearance:
    title: "Appearance Base"
    fields:
      variant:
        type: select
        label: "Variant"
        options:
          - { value: 'soft', label: 'Soft' }
          - { value: 'solid', label: 'Solid' }
      dismissible:
        type: checkbox
        label: "Dismissible"
YAML;
            \file_put_contents($this->tempDir . '/alert.yaml', $baseAlertYaml);

            // Project dir defines alert.yml (testing .yml extension!) overriding variant options and adding custom_glow field + new animation tab
            $projectAlertYml = <<<YAML
tabs:
  appearance:
    fields:
      variant:
        options:
          - { value: 'soft', label: 'Soft' }
          - { value: 'solid', label: 'Solid' }
          - { value: 'custom_glow', label: 'Custom Glow' }
      custom_glow_intensity:
        type: select
        label: "Glow Intensity"
        options:
          - { value: 'low', label: 'Low' }
          - { value: 'high', label: 'High' }
  animation:
    title: "Animation"
    fields:
      fade_in:
        type: checkbox
        label: "Fade In"
YAML;
            \file_put_contents($secondDir . '/alert.yml', $projectAlertYml);

            $provider = new YamlBlockPropSchemaProvider([$this->tempDir, $secondDir]);
            $schema = $provider->getSchema('alert', 'en');

            $this->assertCount(2, $schema['tabs']);
            $tabIds = \array_column($schema['tabs'], 'id');
            $this->assertSame(['appearance', 'animation'], $tabIds);

            // Verify appearance tab preserved dismissible, updated variant options, and added custom_glow_intensity
            $appearanceFields = $schema['tabs'][0]['fields'];
            $fieldNames = \array_column($appearanceFields, 'name');
            $this->assertContains('variant', $fieldNames);
            $this->assertContains('dismissible', $fieldNames);
            $this->assertContains('custom_glow_intensity', $fieldNames);

            // Check variant has 3 options now (custom_glow merged in)
            $variantField = null;
            foreach ($appearanceFields as $f) {
                if ('variant' === $f['name']) {
                    $variantField = $f;
                    break;
                }
            }
            $this->assertNotNull($variantField);
            $this->assertArrayHasKey('options', $variantField);
            $this->assertCount(3, $variantField['options']);
            $this->assertSame('custom_glow', $variantField['options'][2]['value']);
        } finally {
            $this->removeDirectory($secondDir);
        }
    }

    public function testCanonicalTabOrderingPlacesSettingsFirstAndGeneralAdvancedLast(): void
    {
        $commonYaml = <<<YAML
tabs:
  general:
    title: "General"
    fields:
      margin_top:
        type: select
        label: "Margin Top"
  advanced:
    title: "Advanced"
    fields:
      id:
        type: text
        label: "ID"
YAML;
        \file_put_contents($this->tempDir . '/common.yaml', $commonYaml);

        $cardYaml = <<<YAML
tabs:
  settings:
    title: "Settings"
    fields:
      variant:
        type: select
        label: "Card Style"
  custom_developer_tab:
    title: "Custom Tab"
    fields:
      extra:
        type: text
        label: "Extra"
YAML;
        \file_put_contents($this->tempDir . '/card.yaml', $cardYaml);

        $provider = new YamlBlockPropSchemaProvider($this->tempDir);
        $schema = $provider->getSchema('card', 'en');

        $tabIds = \array_column($schema['tabs'], 'id');
        $this->assertSame(['settings', 'custom_developer_tab', 'general', 'advanced'], $tabIds);
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
