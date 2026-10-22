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
        $mockDimension->method('getStage')->willReturn('live');
        $mockDimension->method('getLocale')->willReturn('en');
        $mockDimension->method('getVersion')->willReturn(0);
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

        $mockSnippetRepo = $this->createMock(\Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface::class);
        $mockSnippetRepo->expects($this->once())
            ->method('findBy')
            ->with(
                [
                    'templateKeys' => ['block_preset'],
                    'locale' => 'en',
                    'stage' => 'live',
                ],
                ['title' => 'asc'],
                [\Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            )
            ->willReturn([$mockSnippet]);

        $referenceStore = $this->createMock(\Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface::class);
        $referenceStore->expects($this->once())
            ->method('add')
            ->with('snippet-uuid-1', \Sulu\Snippet\Domain\Model\SnippetInterface::RESOURCE_KEY);

        $provider = new YamlBlockPresetProvider(
            $this->tempDir,
            null,
            $mockSnippetRepo,
            true,
            referenceStore: $referenceStore,
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

    public function testGetPresetsSupportsLabelLocalizationResolution(): void
    {
        $yamlContent = <<<YAML
presets:
    labeled_preset:
        label:
            en: "English Label Title"
            tr: "Türkçe Etiket Başlığı"
        description:
            en: "English Description"
            tr: "Türkçe Açıklama"
        props:
            variant: "bordered"
YAML;
        \file_put_contents($this->tempDir . '/card.yaml', $yamlContent);

        $provider = new YamlBlockPresetProvider($this->tempDir);

        $enPresets = $provider->getPresets('card', 'en');
        $this->assertCount(1, $enPresets);
        $this->assertSame('English Label Title', $enPresets[0]['title']);

        $trPresets = $provider->getPresets('card', 'tr');
        $this->assertCount(1, $trPresets);
        $this->assertSame('Türkçe Etiket Başlığı', $trPresets[0]['title']);
    }

    public function testSectionPresetHydratesReferencedCardAndGridChildPresets(): void
    {
        $cardYaml = <<<YAML
presets:
    hero_split_card:
        label:
            en: "Hero Split Card"
            tr: "Hero Bölünmüş Kart"
        props:
            variant: "elevated"
            color: "primary"
        values:
            title: "Default Card Title"
            text: "<p>Default Card Body</p>"
YAML;
        \file_put_contents($this->tempDir . '/card.yaml', $cardYaml);

        $gridYaml = <<<YAML
presets:
    feature_bento_grid:
        title: "Feature Bento Grid"
        props:
            columns: "3"
            gap: "lg"
        values:
            items:
                - title: "Grid Item 1"
                - title: "Grid Item 2"
YAML;
        \file_put_contents($this->tempDir . '/grid.yaml', $gridYaml);

        $sectionYaml = <<<YAML
presets:
    hero_split_card:
        title: "Hero Split Section"
        props:
            padding: "xl"
        values:
            rows:
                - type: row_item
                  columns:
                      - type: column_item
                        blocks:
                            - type: card
                              title: "Overridden Card Title"
                              props:
                                  preset: "hero_split_card"
                      - type: column_item
                        blocks:
                            - type: grid
                              props:
                                  preset: "feature_bento_grid"
                                  gap: "md"
YAML;
        \file_put_contents($this->tempDir . '/section.yaml', $sectionYaml);

        $provider = new YamlBlockPresetProvider($this->tempDir);
        $sectionPresets = $provider->getPresets('section', 'en');

        $this->assertCount(1, $sectionPresets);
        /** @var list<array{columns: list<array{blocks: list<array{title?: string, text?: string, items?: list<mixed>, props: array<string, mixed>}>}>}> $rows */
        $rows = $sectionPresets[0]['values']['rows'] ?? [];

        $cardBlock = $rows[0]['columns'][0]['blocks'][0];
        $this->assertSame('Overridden Card Title', $cardBlock['title'] ?? null);
        $this->assertSame('<p>Default Card Body</p>', $cardBlock['text'] ?? null);
        $this->assertSame('hero_split_card', $cardBlock['props']['preset']);
        $this->assertSame('elevated', $cardBlock['props']['variant']);
        $this->assertSame('primary', $cardBlock['props']['color']);

        $gridBlock = $rows[0]['columns'][1]['blocks'][0];
        $this->assertSame('feature_bento_grid', $gridBlock['props']['preset']);
        $this->assertSame('3', $gridBlock['props']['columns']);
        $this->assertSame('md', $gridBlock['props']['gap']);
        $this->assertCount(2, $gridBlock['items'] ?? []);
    }

    public function testSectionPresetAutoRegistersInlineChildPresetsAndLinksPresetProp(): void
    {
        $sectionYaml = <<<YAML
presets:
    cta_banner_section:
        label:
            en: "CTA Banner Section"
            tr: "CTA Banner Bölümü"
        description:
            en: "Call to action banner"
            tr: "Çağrı alanı"
        preview_image: "/presets/wireframes/cta.svg"
        props:
            padding: "lg"
        values:
            rows:
                - type: row_item
                  columns:
                      - type: column_item
                        blocks:
                            - type: reusable
                              reusable_block: "uuid-reusable-1"
                              props:
                                  name: "Reusable Ref"
                            - type: button
                              label: "Get Started"
                              url: "https://example.com"
                              props:
                                  variant: "solid"
                                  size: "lg"
                            - type: card
                              title: "First Tier"
                              props:
                                  variant: "bordered"
                            - type: card
                              title: "Second Tier"
                              props:
                                  variant: "elevated"
YAML;
        \file_put_contents($this->tempDir . '/section.yaml', $sectionYaml);

        $provider = new YamlBlockPresetProvider($this->tempDir);

        $this->assertSame([], $provider->getPresets('reusable', 'en'));

        $buttonPresets = $provider->getPresets('button', 'en');
        $this->assertCount(1, $buttonPresets);
        $this->assertSame('cta_banner_section', $buttonPresets[0]['id']);
        $this->assertSame('CTA Banner Section', $buttonPresets[0]['title']);
        $this->assertSame('Call to action banner', $buttonPresets[0]['description']);
        $this->assertSame('/presets/wireframes/cta.svg', $buttonPresets[0]['preview_image']);
        $this->assertSame(['variant' => 'solid', 'size' => 'lg'], $buttonPresets[0]['props']);
        $this->assertSame(['label' => 'Get Started', 'url' => 'https://example.com'], $buttonPresets[0]['values'] ?? []);

        $cardPresets = $provider->getPresets('card', 'en');
        $this->assertCount(2, $cardPresets);
        $this->assertSame('cta_banner_section_card_1', $cardPresets[0]['id']);
        $this->assertSame('CTA Banner Section #1', $cardPresets[0]['title']);
        $this->assertSame(['variant' => 'bordered'], $cardPresets[0]['props']);
        $this->assertSame(['title' => 'First Tier'], $cardPresets[0]['values'] ?? []);

        $this->assertSame('cta_banner_section_card_2', $cardPresets[1]['id']);
        $this->assertSame('CTA Banner Section #2', $cardPresets[1]['title']);
        $this->assertSame(['variant' => 'elevated'], $cardPresets[1]['props']);
        $this->assertSame(['title' => 'Second Tier'], $cardPresets[1]['values'] ?? []);

        $sectionPresets = $provider->getPresets('section', 'en');
        $sectionValues = $sectionPresets[0]['values'] ?? [];
        /** @var list<array{columns: list<array{blocks: list<array{props: array<string, mixed>}>}>}> $sectionRows */
        $sectionRows = $sectionValues['rows'] ?? [];
        $blocks = $sectionRows[0]['columns'][0]['blocks'];
        $this->assertArrayNotHasKey('preset', $blocks[0]['props']);
        $this->assertSame('cta_banner_section', $blocks[1]['props']['preset']);
        $this->assertSame('cta_banner_section_card_1', $blocks[2]['props']['preset']);
        $this->assertSame('cta_banner_section_card_2', $blocks[3]['props']['preset']);
    }

    public function testPathTraversalRejectionInGetPresets(): void
    {
        $outsideDir = \sys_get_temp_dir() . '/sulu_presets_outside_' . \uniqid('', true);
        \mkdir($outsideDir, 0777, true);
        $outsideYaml = <<<YAML
presets:
    evil_preset:
        title: "Evil Traversal Preset"
        props:
            variant: "danger"
YAML;
        \file_put_contents($outsideDir . '/evil.yaml', $outsideYaml);

        try {
            $provider = new YamlBlockPresetProvider($this->tempDir);

            // Compute relative traversal path from tempDir to outsideDir/evil
            $relativePath = '../' . \basename($outsideDir) . '/evil';

            // Without protection, this loads outsideDir/evil.yaml. With protection, it must return []
            $this->assertSame([], $provider->getPresets($relativePath, 'en'));
            $this->assertSame([], $provider->getPresets('../../etc/passwd', 'en'));
            $this->assertSame([], $provider->getPresets('../evil', 'en'));
            $this->assertSame([], $provider->getPresets("card\0evil", 'en'));
            $this->assertSame([], $provider->getPresets('card/sub', 'en'));
            $this->assertSame([], $provider->getPresets('..\\evil', 'en'));
        } finally {
            if (\is_file($outsideDir . '/evil.yaml')) {
                \unlink($outsideDir . '/evil.yaml');
            }
            if (\is_dir($outsideDir)) {
                \rmdir($outsideDir);
            }
        }
    }
}
