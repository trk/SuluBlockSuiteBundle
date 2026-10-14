<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Preset\YamlBlockPresetProvider;

final class YamlBlockPresetProviderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/sulu_presets_test_' . \uniqid('', true);
        \mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . '/*');
            if (false !== $files) {
                foreach ($files as $file) {
                    \unlink($file);
                }
            }
            \rmdir($this->tempDir);
        }
    }

    public function testGetPresetsReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $provider = new YamlBlockPresetProvider($this->tempDir);
        $this->assertSame([], $provider->getPresets('non_existent', 'tr'));
    }

    public function testGetPresetsResolvesTurkishAndEnglishTranslations(): void
    {
        $yamlContent = <<<YAML
presets:
    hero_card:
        title:
            tr: "Hero Kart"
            en: "Hero Card"
        description:
            tr: "Vurgulu hero kartı"
            en: "Highlighted hero card"
        props:
            variant: "solid"
            color: "primary"
YAML;
        \file_put_contents($this->tempDir . '/card.yaml', $yamlContent);

        $provider = new YamlBlockPresetProvider($this->tempDir);

        $trPresets = $provider->getPresets('card', 'tr');
        $this->assertCount(1, $trPresets);
        $this->assertSame('hero_card', $trPresets[0]['id']);
        $this->assertSame('Hero Kart', $trPresets[0]['title']);
        $this->assertSame('Vurgulu hero kartı', $trPresets[0]['description']);
        $this->assertSame(['variant' => 'solid', 'color' => 'primary'], $trPresets[0]['props']);

        $enPresets = $provider->getPresets('card', 'en');
        $this->assertCount(1, $enPresets);
        $this->assertSame('Hero Card', $enPresets[0]['title']);
        $this->assertSame('Highlighted hero card', $enPresets[0]['description']);
    }

    public function testGetPresetsHandlesBlockTypeScopedYaml(): void
    {
        $yamlContent = <<<YAML
headline:
    presets:
        hero_display:
            title:
                tr: "Hero Başlık"
                en: "Hero Display"
            description:
                tr: "H1 seviyesinde başlık"
                en: "H1 display heading"
            props:
                tag: "h1"
                size: "2xl"
YAML;
        \file_put_contents($this->tempDir . '/headline.yaml', $yamlContent);

        $provider = new YamlBlockPresetProvider($this->tempDir);
        $presets = $provider->getPresets('headline', 'tr');

        $this->assertCount(1, $presets);
        $this->assertSame('hero_display', $presets[0]['id']);
        $this->assertSame('Hero Başlık', $presets[0]['title']);
        $this->assertSame('H1 seviyesinde başlık', $presets[0]['description']);
        $this->assertSame(['tag' => 'h1', 'size' => '2xl'], $presets[0]['props']);
    }

    public function testGetPresetsHandlesMalformedYamlGracefully(): void
    {
        \file_put_contents($this->tempDir . '/bad.yaml', '{ invalid: yaml: [');

        $provider = new YamlBlockPresetProvider($this->tempDir);
        $this->assertSame([], $provider->getPresets('bad', 'en'));
    }

    public function testGetPresetsFromSnippetWithBlockConfiguration(): void
    {
        $mockSnippet = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('snippet-uuid-1');

        $mockDimension = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetDimensionContent::class);
        $mockDimension->method('getTemplateKey')->willReturn('block_preset');
        $mockDimension->method('getTitle')->willReturn('Card Preset Title');
        $mockDimension->method('getTemplateData')->willReturn([
            'description' => 'Card Preset Description',
            'block' => [
                [
                    'type' => 'card',
                    'title' => 'Card Title Inside',
                    'text' => 'Card Text Inside',
                    'props' => [
                        'variant' => 'elevated',
                    ],
                ],
            ],
        ]);

        $mockSnippet->method('getDimensionContents')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$mockDimension]));

        $mockSnippetRepo = $this->createStub(\Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface::class);
        $mockSnippetRepo->method('findBy')->willReturn([$mockSnippet]);

        $provider = new YamlBlockPresetProvider(
            $this->tempDir,
            null,
            $mockSnippetRepo,
            true,
        );

        $presets = $provider->getPresets('card', 'en');
        $this->assertCount(1, $presets);
        $this->assertSame('snippet_snippet-uuid-1', $presets[0]['id']);
        $this->assertSame('Card Preset Title', $presets[0]['title']);
        $this->assertSame('Card Preset Description', $presets[0]['description']);
        $this->assertArrayHasKey('values', $presets[0]);
        $values = $presets[0]['values'];
        $this->assertSame('Card Title Inside', $values['title'] ?? null);
    }

    public function testCascadesMultipleDirectoriesAndOverridesById(): void
    {
        $secondDir = \sys_get_temp_dir() . '/sulu_presets_second_' . \uniqid('', true);
        \mkdir($secondDir, 0777, true);

        try {
            $baseYaml = <<<YAML
presets:
    preset_1:
        title: "Base Preset 1"
        props: { color: "blue" }
    preset_2:
        title: "Base Preset 2"
        props: { color: "green" }
YAML;
            \file_put_contents($this->tempDir . '/hero.yaml', $baseYaml);

            $overrideYaml = <<<YAML
presets:
    preset_2:
        title: "Overridden Preset 2"
        props: { color: "red" }
    preset_3:
        title: "New Preset 3"
        props: { color: "purple" }
YAML;
            \file_put_contents($secondDir . '/hero.yaml', $overrideYaml);

            $provider = new YamlBlockPresetProvider([$this->tempDir, $secondDir]);
            $presets = $provider->getPresets('hero', 'en');

            $this->assertCount(3, $presets);
            $presetIds = \array_column($presets, 'id');
            $this->assertSame(['preset_1', 'preset_2', 'preset_3'], $presetIds);

            // preset_2 was overridden by second directory
            $this->assertSame('Overridden Preset 2', $presets[1]['title']);
            $this->assertSame(['color' => 'red'], $presets[1]['props']);
        } finally {
            if (\is_dir($secondDir)) {
                $files = \glob($secondDir . '/*');
                if (false !== $files) {
                    foreach ($files as $file) {
                        \unlink($file);
                    }
                }
                \rmdir($secondDir);
            }
        }
    }

    public function testSupportsListStyleYamlAndFiltersOutTestPresets(): void
    {
        $yamlContent = <<<YAML
presets:
    - id: valid_gallery
      name: "Masonry Lightbox"
      description: "Clean gallery layout"
      props:
          columns: "3"
    - id: test_preset_ignore
      name: "Should Be Ignored"
      props:
          columns: "2"
    - id: another_id
      title: "Test Başlık Şablonu"
      props:
          columns: "4"
YAML;
        \file_put_contents($this->tempDir . '/gallery.yaml', $yamlContent);

        $provider = new YamlBlockPresetProvider($this->tempDir);
        $presets = $provider->getPresets('gallery', 'en');

        $this->assertCount(1, $presets);
        $this->assertSame('valid_gallery', $presets[0]['id']);
        $this->assertSame('Masonry Lightbox', $presets[0]['title']);
    }
}

