<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Totoglu\SuluBlockSuiteBundle\Command\InitBlockSuiteCommand;

final class InitBlockSuiteCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/init_block_suite_test_' . \uniqid('', true);
        \mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testInitCopiesCoreExampleSnippetsAndDoesNotOverwriteExistingFilesByDefault(): void
    {
        $projectDir = $this->tempDir . '/project';
        \mkdir($projectDir, 0777, true);

        $command = new InitBlockSuiteCommand($projectDir);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $snippetsDir = $projectDir . '/config/templates/snippets';
        self::assertFileExists($snippetsDir . '/page_layout.xml');
        self::assertFileExists($snippetsDir . '/reusable_block.xml');
        self::assertFileExists($snippetsDir . '/block_preset.xml');
        self::assertStringContainsString('3 created, 0 overwritten, 0 skipped', $tester->getDisplay());

        // Customize one file and verify second run without --force skips all existing files
        \file_put_contents($snippetsDir . '/page_layout.xml', '<!-- custom project layout -->');

        $secondExitCode = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $secondExitCode);
        self::assertSame('<!-- custom project layout -->', \file_get_contents($snippetsDir . '/page_layout.xml'));
        self::assertStringContainsString('0 created, 0 overwritten, 3 skipped', $tester->getDisplay());

        // Verify --force overwrites existing files
        $forceExitCode = $tester->execute(['--force' => true]);
        self::assertSame(Command::SUCCESS, $forceExitCode);
        self::assertNotSame('<!-- custom project layout -->', \file_get_contents($snippetsDir . '/page_layout.xml'));
        self::assertStringContainsString('0 created, 3 overwritten, 0 skipped', $tester->getDisplay());
    }

    public function testInitSupportsBundleDiscoveryOverlayAndNoPresetsOption(): void
    {
        $projectDir = $this->tempDir . '/project';
        $frameworkBundleDir = $this->tempDir . '/framework-bundle';
        \mkdir($frameworkBundleDir . '/docs/examples/includes', 0777, true);
        \mkdir($frameworkBundleDir . '/docs/examples/snippets', 0777, true);
        \mkdir($frameworkBundleDir . '/docs/examples/presets', 0777, true);

        \file_put_contents($frameworkBundleDir . '/docs/examples/includes/content_block_types.xml', '<types/>');
        \file_put_contents($frameworkBundleDir . '/docs/examples/snippets/page_layout.xml', '<template><key>page_layout_framework</key></template>');
        \file_put_contents($frameworkBundleDir . '/docs/examples/presets/hero.yaml', "hero:\n    presets: {}\n");

        $command = new InitBlockSuiteCommand(
            projectDirectory: $projectDir,
            presetsDirectory: $projectDir . '/config/blocks/presets',
            bundlesMetadata: [
                'SuluBlockSuiteBundle' => ['path' => \dirname(__DIR__, 2)],
                'CustomFrameworkBundle' => ['path' => $frameworkBundleDir . '/src'],
            ],
        );

        $tester = new CommandTester($command);

        // Dry run first: nothing written to disk
        $dryRunExit = $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $dryRunExit);
        self::assertDirectoryDoesNotExist($projectDir . '/config');
        self::assertStringContainsString('Dry-run complete: 5 created', $tester->getDisplay());

        // Run with --no-presets: copies includes and snippets (with framework override for page_layout.xml), skips presets
        $noPresetsExit = $tester->execute(['--no-presets' => true]);
        self::assertSame(Command::SUCCESS, $noPresetsExit);
        self::assertFileExists($projectDir . '/config/templates/includes/content_block_types.xml');
        self::assertFileExists($projectDir . '/config/templates/snippets/page_layout.xml');
        self::assertSame(
            '<template><key>page_layout_framework</key></template>',
            \file_get_contents($projectDir . '/config/templates/snippets/page_layout.xml'),
        );
        self::assertFileDoesNotExist($projectDir . '/config/blocks/presets/hero.yaml');

        // Run without --no-presets: creates the preset while skipping already-created includes and snippets
        $fullExit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $fullExit);
        self::assertFileExists($projectDir . '/config/blocks/presets/hero.yaml');
        self::assertStringContainsString('1 created, 0 overwritten, 4 skipped', $tester->getDisplay());
    }

    public function testInitFailsWhenCustomSourceDoesNotExist(): void
    {
        $command = new InitBlockSuiteCommand($this->tempDir . '/project');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--source' => $this->tempDir . '/non_existent_dir']);
        self::assertSame(Command::FAILURE, $exitCode);
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = \scandir($dir);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . '/' . $item;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @\unlink($path);
            }
        }

        @\rmdir($dir);
    }
}
