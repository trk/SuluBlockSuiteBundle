<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds starter snippet XML templates, shared block type includes, and preset YAML files into the host project.
 */
#[AsCommand(
    name: 'sulu:block-suite:init',
    description: 'Initialize Sulu Block Suite snippet templates, includes, and presets in the project without overwriting existing files',
    aliases: ['sulu_block_suite:init'],
)]
final class InitBlockSuiteCommand extends Command
{
    private readonly string $presetsTargetDirectory;

    /**
     * @param string|array<array-key, string> $presetsDirectory
     * @param array<string, array<string, mixed>> $bundlesMetadata
     * @param list<string> $exampleDirectories
     */
    public function __construct(
        private readonly string $projectDirectory,
        string|array $presetsDirectory = '',
        private readonly array $bundlesMetadata = [],
        private readonly array $exampleDirectories = [],
    ) {
        parent::__construct();

        $resolvedPresetsDir = '';
        if (\is_array($presetsDirectory)) {
            $first = \reset($presetsDirectory);
            $resolvedPresetsDir = \is_string($first) ? \trim($first) : '';
        } else {
            $resolvedPresetsDir = \trim($presetsDirectory);
        }

        $this->presetsTargetDirectory = '' !== $resolvedPresetsDir
            ? \rtrim($resolvedPresetsDir, '/\\')
            : \rtrim($this->projectDirectory, '/\\') . '/config/blocks/presets';
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files in the project')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files to be copied without writing to disk')
            ->addOption('no-presets', null, InputOption::VALUE_NONE, 'Skip copying example preset YAML files')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Custom examples source directory path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = \rtrim(\trim($this->projectDirectory), '/\\');
        if ('' === $projectDir) {
            $io->error('Project directory is not configured.');

            return Command::FAILURE;
        }

        $sourceOpt = $input->getOption('source');
        $customSource = \is_string($sourceOpt) && '' !== \trim($sourceOpt) ? \rtrim(\trim($sourceOpt), '/\\') : null;
        if (null !== $customSource && !\is_dir($customSource)) {
            $io->error(\sprintf('Specified source directory "%s" does not exist.', $customSource));

            return Command::FAILURE;
        }

        $sourceDirs = null !== $customSource ? [$customSource] : $this->resolveExampleDirectories();
        if ([] === $sourceDirs) {
            $io->warning('No example directories were found to initialize from.');

            return Command::SUCCESS;
        }

        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $skipPresets = (bool) $input->getOption('no-presets');

        $plan = $this->buildCopyPlan($projectDir, $sourceDirs, $skipPresets);
        if ([] === $plan) {
            $io->warning('No example files found in the configured source directories.');

            return Command::SUCCESS;
        }

        $createdCount = 0;
        $overwrittenCount = 0;
        $skippedCount = 0;
        /** @var list<array{string, string, string}> $rows */
        $rows = [];

        foreach ($plan as $item) {
            $targetPath = $item['target'];
            $relativeTarget = $this->makeRelativePath($projectDir, $targetPath);
            $exists = \is_file($targetPath);

            if ($exists && !$force) {
                ++$skippedCount;
                $rows[] = [$item['category'], $relativeTarget, 'skipped (exists)'];

                continue;
            }

            if ($dryRun) {
                if ($exists) {
                    ++$overwrittenCount;
                    $rows[] = [$item['category'], $relativeTarget, 'dry-run (would overwrite)'];
                } else {
                    ++$createdCount;
                    $rows[] = [$item['category'], $relativeTarget, 'dry-run (would create)'];
                }

                continue;
            }

            $targetDir = \dirname($targetPath);
            if (!\is_dir($targetDir) && !@\mkdir($targetDir, 0775, true) && !\is_dir($targetDir)) {
                $io->error(\sprintf('Failed to create directory "%s".', $targetDir));

                return Command::FAILURE;
            }

            if (!@\copy($item['source'], $targetPath)) {
                $io->error(\sprintf('Failed to copy "%s" to "%s".', $item['source'], $targetPath));

                return Command::FAILURE;
            }

            if ($exists) {
                ++$overwrittenCount;
                $rows[] = [$item['category'], $relativeTarget, 'overwritten'];
            } else {
                ++$createdCount;
                $rows[] = [$item['category'], $relativeTarget, 'created'];
            }
        }

        $io->table(['Category', 'Target File', 'Status'], $rows);

        $summary = \sprintf(
            '%s complete: %d created, %d overwritten, %d skipped.',
            $dryRun ? 'Dry-run' : 'Initialization',
            $createdCount,
            $overwrittenCount,
            $skippedCount,
        );

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveExampleDirectories(): array
    {
        if ([] !== $this->exampleDirectories) {
            $valid = [];
            foreach ($this->exampleDirectories as $dir) {
                $trimmed = \rtrim(\trim($dir), '/\\');
                if ('' !== $trimmed && \is_dir($trimmed)) {
                    $valid[] = $trimmed;
                }
            }

            return $valid;
        }

        /** @var array<string, string> $discovered */
        $discovered = [];

        $coreExamples = \dirname(__DIR__, 2) . '/docs/examples';
        if (\is_dir($coreExamples)) {
            $realCore = \realpath($coreExamples);
            $discovered[false !== $realCore ? $realCore : $coreExamples] = $coreExamples;
        }

        foreach ($this->bundlesMetadata as $meta) {
            $bundlePath = $meta['path'] ?? null;
            if (!\is_string($bundlePath) || '' === \trim($bundlePath)) {
                continue;
            }

            $candidates = [
                \rtrim($bundlePath, '/\\') . '/docs/examples',
                \dirname(\rtrim($bundlePath, '/\\')) . '/docs/examples',
            ];

            foreach ($candidates as $candidate) {
                if (\is_dir($candidate)) {
                    $real = \realpath($candidate);
                    $key = false !== $real ? $real : $candidate;
                    $discovered[$key] = $candidate;
                    break;
                }
            }
        }

        return \array_values($discovered);
    }

    /**
     * Builds the ordered list of files to copy. Later source directories override earlier ones for the same target path.
     *
     * @param list<string> $sourceDirs
     *
     * @return list<array{category: string, source: string, target: string}>
     */
    private function buildCopyPlan(string $projectDir, array $sourceDirs, bool $skipPresets): array
    {
        /** @var array<string, array{category: string, source: string, target: string}> $byTarget */
        $byTarget = [];

        $groups = [
            [
                'category' => 'include',
                'subDir' => 'includes',
                'pattern' => '*.xml',
                'targetDir' => $projectDir . '/config/templates/includes',
            ],
            [
                'category' => 'snippet',
                'subDir' => 'snippets',
                'pattern' => '*.xml',
                'targetDir' => $projectDir . '/config/templates/snippets',
            ],
        ];

        if (!$skipPresets) {
            $groups[] = [
                'category' => 'preset',
                'subDir' => 'presets',
                'pattern' => '*.yaml',
                'targetDir' => $this->presetsTargetDirectory,
            ];
        }

        foreach ($sourceDirs as $sourceDir) {
            foreach ($groups as $group) {
                $files = \glob($sourceDir . '/' . $group['subDir'] . '/' . $group['pattern']) ?: [];
                \sort($files);
                foreach ($files as $file) {
                    if (!\is_file($file)) {
                        continue;
                    }
                    $targetPath = $group['targetDir'] . '/' . \basename($file);
                    $byTarget[$targetPath] = [
                        'category' => $group['category'],
                        'source' => $file,
                        'target' => $targetPath,
                    ];
                }
            }
        }

        return \array_values($byTarget);
    }

    private function makeRelativePath(string $projectDir, string $path): string
    {
        $prefix = $projectDir . '/';
        if (\str_starts_with($path, $prefix)) {
            return \substr($path, \strlen($prefix));
        }

        return $path;
    }
}
