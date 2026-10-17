<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Command;

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports CMS-managed `block_preset` snippets from the database into project-level YAML preset files.
 */
#[AsCommand(
    name: 'sulu_block_suite:presets:export',
    description: 'Export CMS block_preset snippets into project YAML preset files (config/blocks/presets/*.yaml)',
)]
final class ExportBlockPresetsCommand extends Command
{
    private readonly string $targetDirectory;

    /**
     * @param string|array<array-key, string> $yamlDirectory
     */
    public function __construct(
        string|array $yamlDirectory,
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
    ) {
        parent::__construct();
        if (\is_array($yamlDirectory)) {
            $first = \reset($yamlDirectory);
            $this->targetDirectory = \is_string($first) ? $first : '';
        } else {
            $this->targetDirectory = $yamlDirectory;
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('block-type', 'b', InputOption::VALUE_OPTIONAL, 'Filter export to a single block type (e.g. card, headline)')
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Snippet locale to export from', 'en')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview exported YAML without writing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->snippetRepository) {
            $io->error('SnippetRepositoryInterface is not available.');

            return Command::FAILURE;
        }

        if ('' === \trim($this->targetDirectory)) {
            $io->error('Preset YAML target directory is not configured.');

            return Command::FAILURE;
        }

        $localeOpt = $input->getOption('locale');
        $locale = \is_string($localeOpt) && '' !== \trim($localeOpt) ? \trim($localeOpt) : 'en';

        $blockTypeOpt = $input->getOption('block-type');
        $filterBlockType = \is_string($blockTypeOpt) && '' !== \trim($blockTypeOpt) ? \trim($blockTypeOpt) : null;

        $dryRun = (bool) $input->getOption('dry-run');

        $snippets = $this->snippetRepository->findBy(
            [
                'templateKeys' => ['block_preset'],
                'locale' => $locale,
            ],
            ['title' => 'asc'],
            [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
        );

        /** @var array<string, array<string, array<string, mixed>>> $exportedByBlock */
        $exportedByBlock = [];
        $exportedCount = 0;

        foreach ($snippets as $snippet) {
            $content = $this->selectMatchingDimensionContent($snippet, $locale);
            if (null === $content) {
                continue;
            }

            $templateData = $content->getTemplateData();
            $rawTitle = $content->getTitle();
            if ((null === $rawTitle || '' === \trim($rawTitle)) && isset($templateData['title']) && \is_string($templateData['title'])) {
                $rawTitle = $templateData['title'];
            }
            $title = \trim((string) ($rawTitle ?? ''));
            if ('' === $title || 1 === \preg_match('/^test(\b|_|-|\s|$)/i', $title)) {
                continue;
            }

            $blockEntry = null;
            if (isset($templateData['block']) && \is_array($templateData['block']) && isset($templateData['block'][0]) && \is_array($templateData['block'][0])) {
                $blockEntry = $templateData['block'][0];
            }

            $configuredType = null;
            if (null !== $blockEntry && isset($blockEntry['type']) && \is_string($blockEntry['type'])) {
                $configuredType = \trim($blockEntry['type']);
            } elseif (isset($templateData['target_block']) && \is_string($templateData['target_block'])) {
                $configuredType = \trim($templateData['target_block']);
            }

            if (null === $configuredType || '' === $configuredType) {
                continue;
            }

            if (null !== $filterBlockType && $configuredType !== $filterBlockType) {
                continue;
            }

            $presetKeyFromData = isset($templateData['preset_key']) && \is_string($templateData['preset_key']) ? \trim($templateData['preset_key']) : '';
            $slug = '' !== $presetKeyFromData
                ? \strtolower((string) \preg_replace('/[^a-zA-Z0-9]+/', '_', $presetKeyFromData))
                : \strtolower((string) \preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
            $slug = \trim($slug, '_');
            if ('' === $slug) {
                $slug = 'preset_' . \substr($snippet->getUuid(), 0, 8);
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
                    $propKey = (string) $k;
                    if (!\in_array($propKey, ['preset', '_preset'], true) && null !== $v && '' !== $v) {
                        $props[$propKey] = $v;
                    }
                }
            }

            /** @var array<string, mixed> $values */
            $values = [];
            if (null !== $blockEntry) {
                foreach ($blockEntry as $k => $v) {
                    $keyStr = (string) $k;
                    if (!\in_array($keyStr, ['type', 'props', 'preset', '_preset', '_id'], true) && null !== $v && '' !== $v) {
                        $values[$keyStr] = $v;
                    }
                }
            }

            $presetDef = [
                'title' => [$locale => $title],
            ];

            if (isset($templateData['description']) && \is_string($templateData['description']) && '' !== \trim($templateData['description'])) {
                $presetDef['description'] = [$locale => \trim($templateData['description'])];
            }

            $presetDef['props'] = $props;
            if ([] !== $values) {
                $presetDef['values'] = $values;
            }

            $exportedByBlock[$configuredType][$slug] = $presetDef;
            ++$exportedCount;
        }

        if (0 === $exportedCount) {
            $io->note('No non-test block_preset snippets found to export.');

            return Command::SUCCESS;
        }

        $baseDir = \rtrim($this->targetDirectory, '/');
        if (!$dryRun && !\is_dir($baseDir)) {
            \mkdir($baseDir, 0775, true);
        }

        foreach ($exportedByBlock as $blockType => $newPresets) {
            $filePath = $baseDir . '/' . $blockType . '.yaml';
            /** @var array<string, mixed> $existingPresets */
            $existingPresets = [];

            if (\is_file($filePath)) {
                try {
                    $parsed = Yaml::parseFile($filePath);
                    if (\is_array($parsed) && isset($parsed['presets']) && \is_array($parsed['presets'])) {
                        $existingPresets = $parsed['presets'];
                    }
                } catch (\Throwable) {
                    $existingPresets = [];
                }
            }

            $merged = \array_merge($existingPresets, $newPresets);
            $yamlContent = Yaml::dump(['presets' => $merged], 5, 4);

            if ($dryRun) {
                $io->section(\sprintf('[Dry Run] %s (%d preset(s))', $filePath, \count($newPresets)));
                $io->writeln($yamlContent);
            } else {
                \file_put_contents($filePath, $yamlContent);
                $io->writeln(\sprintf('Exported <info>%d</info> preset(s) to <comment>%s</comment>', \count($newPresets), $filePath));
            }
        }

        $io->success(\sprintf('Successfully processed %d snippet preset(s).', $exportedCount));

        return Command::SUCCESS;
    }

    private function selectMatchingDimensionContent(
        SnippetInterface $snippet,
        string $locale,
    ): ?SnippetDimensionContentInterface {
        $bestMatch = null;
        foreach ($snippet->getDimensionContents() as $content) {
            if ('block_preset' !== $content->getTemplateKey()) {
                continue;
            }
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                continue;
            }
            if ($content->getLocale() === $locale) {
                if (DimensionContentInterface::STAGE_LIVE === $content->getStage()) {
                    return $content;
                }
                if (null === $bestMatch || null === $bestMatch->getLocale()) {
                    $bestMatch = $content;
                }
            } elseif (null === $content->getLocale() && null === $bestMatch) {
                $bestMatch = $content;
            }
        }

        return $bestMatch;
    }
}
