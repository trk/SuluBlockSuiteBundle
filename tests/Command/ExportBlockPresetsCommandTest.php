<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Command;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use Totoglu\SuluBlockSuiteBundle\Command\ExportBlockPresetsCommand;

final class ExportBlockPresetsCommandTest extends TestCase
{
    private string $tempExportDir;

    protected function setUp(): void
    {
        $this->tempExportDir = \sys_get_temp_dir() . '/export_presets_test_' . \uniqid('', true);
        \mkdir($this->tempExportDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempExportDir)) {
            $files = \glob($this->tempExportDir . '/*');
            if (\is_array($files)) {
                foreach ($files as $file) {
                    \unlink($file);
                }
            }
            \rmdir($this->tempExportDir);
        }
    }

    public function testExportBlockPresetsCommandWritesYamlFileMergingWithExistingPresets(): void
    {
        // Pre-populate an existing YAML preset in target directory
        \file_put_contents(
            $this->tempExportDir . '/card.yaml',
            Yaml::dump([
                'presets' => [
                    'existing_preset' => [
                        'label' => 'Existing Card Preset',
                        'group' => 'Default',
                        'props' => ['card_style' => 'card-default'],
                    ],
                ],
            ], 4, 2),
        );

        $dimensionContent = $this->createStub(SnippetDimensionContentInterface::class);
        $dimensionContent->method('getLocale')->willReturn('en');
        $dimensionContent->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $dimensionContent->method('getTemplateKey')->willReturn('block_preset');
        $dimensionContent->method('getTemplateData')->willReturn([
            'title' => 'Glass Hero Card',
            'preset_key' => 'glass_hero_card',
            'target_block' => 'card',
            'preset_group' => 'Hero',
            'description' => 'Frosted glass card with large padding',
            'props' => [
                'card_style' => 'card-primary',
                'card_padding' => 'large',
                '_preset' => 'should_be_stripped',
            ],
        ]);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-preset-001');
        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$dimensionContent]));

        $snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $snippetRepository->expects(self::once())
            ->method('findBy')
            ->willReturn([$snippet]);

        $command = new ExportBlockPresetsCommand(
            [$this->tempExportDir],
            $snippetRepository,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--locale' => 'en']);

        self::assertSame(0, $exitCode);
        self::assertFileExists($this->tempExportDir . '/card.yaml');

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($this->tempExportDir . '/card.yaml');
        self::assertIsArray($parsed['presets'] ?? null);
        /** @var array<string, array<string, mixed>> $presets */
        $presets = $parsed['presets'];

        self::assertArrayHasKey('existing_preset', $presets);
        self::assertArrayHasKey('glass_hero_card', $presets);
        self::assertSame(['en' => 'Glass Hero Card'], $presets['glass_hero_card']['title']);
        self::assertSame(['en' => 'Frosted glass card with large padding'], $presets['glass_hero_card']['description']);
        self::assertSame([
            'card_style' => 'card-primary',
            'card_padding' => 'large',
        ], $presets['glass_hero_card']['props']);
    }
}
