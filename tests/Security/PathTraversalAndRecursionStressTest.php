<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProvider;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\YamlBlockPresetProvider;
use Totoglu\SuluBlockSuiteBundle\Twig\LayoutTwigExtension;
use Twig\Environment;
use Twig\Loader\LoaderInterface;

/**
 * Empirical Adversarial Stress Test Suite for Milestone 2:
 * 1. Path traversal controls in YamlBlockPresetProvider and PageLayoutProvider
 * 2. Recursion guards and depth limits in LayoutTwigExtension::blocksContainType()
 */
final class PathTraversalAndRecursionStressTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/sulu_m2_stress_' . \uniqid('', true);
        \mkdir($this->tempDir . '/inside/presets', 0777, true);
        \mkdir($this->tempDir . '/outside', 0777, true);

        // Put legitimate preset and layout inside
        \file_put_contents($this->tempDir . '/inside/presets/card.yaml', "presets:\n  card_1:\n    title: 'Valid Card'\n    props: {}\n");
        \file_put_contents($this->tempDir . '/inside/default.yaml', "title: 'Default Layout'\nblocks:\n  - type: headline\n    title: 'Hello'\n");

        // Put sensitive targets outside
        \file_put_contents($this->tempDir . '/outside/doctrine.yaml', "doctrine:\n  dbal:\n    password: 'super_secret_password'\n");
        \file_put_contents($this->tempDir . '/outside/win.ini', "[fonts]\nArial=1\n");
        \file_put_contents($this->tempDir . '/outside/secret_layout.yaml', "title: 'Exfiltrated Layout'\nblocks: []\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = \scandir($dir);
        if (false !== $items) {
            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }
                $path = $dir . \DIRECTORY_SEPARATOR . $item;
                if (\is_link($path)) {
                    \unlink($path);
                } elseif (\is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    \unlink($path);
                }
            }
        }
        \rmdir($dir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePathTraversalPayloads(): iterable
    {
        yield 'relative traversal to doctrine config' => ['../../config/packages/doctrine'];
        yield 'windows traversal to win.ini' => ['..\..\windows\win.ini'];
        yield 'simple dot-dot-slash' => ['../'];
        yield 'simple dot-dot' => ['..'];
        yield 'single dot' => ['.'];
        yield 'URL encoded double dot slash' => ['%2e%2e%2f'];
        yield 'URL encoded double dot with raw slash' => ['%2e%2e/'];
        yield 'raw dot-dot with URL encoded slash' => ['..%2f'];
        yield 'raw dot-dot with URL encoded backslash' => ['..%5c'];
        yield 'null byte embedded' => ["card\0evil"];
        yield 'null byte leading before traversal' => ["\0../../config/packages/doctrine"];
        yield 'null byte solo' => ["\0"];
        yield 'null byte trailing' => ["card\0"];
        yield 'whitespace padded traversal' => ['  ../../config/packages/doctrine  '];
        yield 'whitespace padded backslash traversal' => ['  ..\\..\\windows\\win.ini  '];
        yield 'tab and newline padded traversal' => ["\t../../config/packages/doctrine\n"];
        yield 'CRLF padded traversal' => ["\r\n../../config/packages/doctrine\r\n"];
        yield 'whitespace padded valid name' => ['  card  '];
        yield 'trailing space' => ['card '];
        yield 'leading space' => [' card'];
        yield 'tabs and newlines around valid name' => ["\tcard\n"];
        yield 'all spaces' => ['   '];
        yield 'empty string' => [''];
        yield 'extension suffix manipulation' => ['card.yaml'];
        yield 'semicolon injection' => ['card;'];
        yield 'dollar sign' => ['card$'];
        yield 'at symbol' => ['card@'];
        yield 'slash subdirectory' => ['card/child'];
        yield 'backslash subdirectory' => ['card\child'];
        yield 'asterisk glob wildcard' => ['*'];
        yield 'question mark wildcard' => ['?'];
        yield 'character class glob' => ['[a-z]'];
        yield 'glob pattern with name' => ['card*'];
        yield 'question mark with name' => ['card?'];
        yield 'html script tag' => ['card<script>alert(1)</script>'];
        yield 'sql injection attempt' => ["card' OR '1'='1"];
        yield 'pipe character' => ['card|pipe'];
        yield 'colon character' => ['card:colon'];
        yield 'hash fragment' => ['card#fragment'];
        yield 'percent hex encoding' => ['card%00'];
        yield 'unicode right-to-left override' => ["card\u{202E}txt"];
        yield 'unicode zero character' => ["card\u{0000}"];
        yield 'etc passwd deep traversal' => ['../../../../../../../../etc/passwd'];
        yield 'nested slash dot bypass attempt' => ['....//....//config/packages/doctrine'];
        yield 'semicolon path parameter attempt' => ['..;/..;/config'];
    }

    #[DataProvider('providePathTraversalPayloads')]
    public function testYamlBlockPresetProviderRejectsPathTraversalPayloads(string $payload): void
    {
        $provider = new YamlBlockPresetProvider($this->tempDir . '/inside');

        $result = $provider->getPresets($payload, 'en');

        self::assertSame(
            [],
            $result,
            \sprintf('YamlBlockPresetProvider::getPresets() must return empty array for payload "%s"', \addcslashes($payload, "\0..\37")),
        );
    }

    public function testYamlBlockPresetProviderDoesNotLeakOutsideFilesAndBlocksSymlinks(): void
    {
        $insideDir = $this->tempDir . '/inside';
        $outsideDir = $this->tempDir . '/outside';

        // Legitimate preset must load
        $provider = new YamlBlockPresetProvider($insideDir);
        $legit = $provider->getPresets('card', 'en');
        self::assertCount(1, $legit);
        self::assertSame('card_1', $legit[0]['id']);

        // Traversal attempt to outside directory must return empty
        self::assertSame([], $provider->getPresets('../outside/doctrine', 'en'));
        self::assertSame([], $provider->getPresets('../../outside/doctrine', 'en'));
        self::assertSame([], $provider->getPresets('../outside/win', 'en'));

        // Traversal attempt to real repository doctrine config must return empty
        self::assertSame([], $provider->getPresets('../../config/packages/doctrine', 'en'));

        // Symlink pointing to outside file inside the directory must be rejected by realpath check
        $symlinkPath = $insideDir . '/presets/doctrine_symlink.yaml';
        @\symlink($outsideDir . '/doctrine.yaml', $symlinkPath);

        if (\is_link($symlinkPath)) {
            // Even though the filename passes regex ('doctrine_symlink'), the realpath does not start with $insideDir
            $symlinkResult = $provider->getPresets('doctrine_symlink', 'en');
            self::assertSame([], $symlinkResult, 'Symlink pointing outside base directory must be rejected.');
        }
    }

    #[DataProvider('providePathTraversalPayloads')]
    public function testPageLayoutProviderRejectsPathTraversalPayloads(string $payload): void
    {
        $provider = new PageLayoutProvider(null, null, null, [$this->tempDir . '/inside']);

        $result = $provider->getLayout($payload, 'en');

        self::assertNull(
            $result,
            \sprintf('PageLayoutProvider::getLayout() must return null for payload "%s"', \addcslashes($payload, "\0..\37")),
        );
    }

    public function testPageLayoutProviderRejectsPipeSeparatedTraversalAttempts(): void
    {
        $provider = new PageLayoutProvider(null, null, null, [$this->tempDir . '/inside']);

        // Traversal only in pipe chain: must all be rejected and return null
        self::assertNull($provider->getLayout('../../config/packages/doctrine|..\..\windows\win.ini|%2e%2e%2f'));
        self::assertNull($provider->getLayout('../|../|../'));
        self::assertNull($provider->getLayout("nonexistent\0|fake\0|bad\0"));
        self::assertNull($provider->getLayout('../outside/secret_layout|../../outside/doctrine'));
        self::assertNull($provider->getLayout("card\0evil|default\0evil"));

        // Fallback chain with traversal candidate: traversal candidate must be ignored
        // 1. When non-existent comes first, traversal comes second, valid comes third
        $resolved = $provider->getLayout('nonexistent|../../config/packages/doctrine|default', 'en');
        self::assertNotNull($resolved);
        self::assertSame('Default Layout', $resolved['title']);

        // 2. Traversal first, valid second: traversal is rejected, valid is loaded
        $resolved2 = $provider->getLayout('../../config/packages/doctrine|default', 'en');
        self::assertNotNull($resolved2);
        self::assertSame('Default Layout', $resolved2['title']);
    }

    public function testPageLayoutProviderDoesNotLeakOutsideFilesAndBlocksSymlinks(): void
    {
        $insideDir = $this->tempDir . '/inside';
        $outsideDir = $this->tempDir . '/outside';

        $provider = new PageLayoutProvider(null, null, null, [$insideDir]);

        // Legitimate layout must load
        $legit = $provider->getLayout('default', 'en');
        self::assertNotNull($legit);
        self::assertSame('Default Layout', $legit['title']);

        // Traversal to outside files must return null
        self::assertNull($provider->getLayout('../outside/secret_layout'));
        self::assertNull($provider->getLayout('../../outside/secret_layout'));
        self::assertNull($provider->getLayout('../../outside/doctrine'));

        // Traversal attempt to real repository doctrine config must return null
        self::assertNull($provider->getLayout('../../config/packages/doctrine'));

        // Symlink pointing to outside file inside the directory must be rejected by realpath check
        $symlinkPath = $insideDir . '/secret_symlink.yaml';
        @\symlink($outsideDir . '/secret_layout.yaml', $symlinkPath);

        if (\is_link($symlinkPath)) {
            // Identifier passes regex ('secret_symlink'), but realpath is outside $insideDir
            $symlinkResult = $provider->getLayout('secret_symlink', 'en');
            self::assertNull($symlinkResult, 'Symlink pointing outside layout directory must be rejected.');
        }
    }

    public function testPageLayoutProviderRenderLayoutRejectsTraversalStrings(): void
    {
        $insideDir = $this->tempDir . '/inside';
        $provider = new PageLayoutProvider(null, null, null, [$insideDir]);

        // Render layout with traversal string returns empty string
        self::assertSame('', $provider->renderLayout('../../config/packages/doctrine'));
        self::assertSame('', $provider->renderLayout('..\..\windows\win.ini'));
        self::assertSame('', $provider->renderLayout('%2e%2e%2f'));
        self::assertSame('', $provider->renderLayout("default\0"));
    }

    public function testLayoutTwigExtensionSelfReferentialSnippetRecursion(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // Self-referential snippet: snippet-A embeds snippet-A
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'id' => 'snippet-A',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-A'],
            ],
        ];

        $start = \microtime(true);
        // Searching for target_block must return false cleanly without stack overflow
        $result = $extension->hasBlock($layout, 'target_block');
        $duration = (\microtime(true) - $start) * 1000;

        self::assertFalse($result);
        self::assertLessThan(100.0, $duration, 'Self-referential recursion must terminate in < 100ms.');

        // Searching for 'reusable' must return true because the root block has type 'reusable'
        self::assertTrue($extension->hasBlock($layout, 'reusable'));
    }

    public function testLayoutTwigExtensionMutualCyclicSnippetsRecursion(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // 2-Cycle: Snippet A -> Snippet B -> Snippet A
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'id' => 'snippet-A',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-B'],
                        ],
                    ];
                }
                if ('snippet-B' === $id) {
                    return [
                        'id' => 'snippet-B',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-A'],
            ],
        ];

        // Must return false when block is absent in cyclic snippet loop
        self::assertFalse($extension->hasBlock($layout, 'card'));
        self::assertFalse($extension->hasBlock($layout, 'nonexistent'));
    }

    public function testLayoutTwigExtensionMutualCyclicSnippetsFindsTargetBlockWhenPresent(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // Cycle: Snippet A -> Snippet B -> Snippet A, BUT Snippet B also contains target 'card' block
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'id' => 'snippet-A',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-B'],
                        ],
                    ];
                }
                if ('snippet-B' === $id) {
                    return [
                        'id' => 'snippet-B',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                            ['type' => 'card', 'title' => 'Found Target'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-A'],
            ],
        ];

        // Must find 'card' despite the circular snippet-A reference preceding it
        self::assertTrue($extension->hasBlock($layout, 'card'));
    }

    public function testLayoutTwigExtensionLargeNCycleSnippetRecursion(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // 5-Cycle: 1 -> 2 -> 3 -> 4 -> 5 -> 1
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if (1 === \preg_match('/^ring-(\d+)$/', $id, $matches)) {
                    $curr = (int) $matches[1];
                    $next = ($curr % 5) + 1;

                    return [
                        'id' => $id,
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'ring-' . $next],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'ring-1'],
            ],
        ];

        self::assertFalse($extension->hasBlock($layout, 'target_block'));
    }

    public function testLayoutTwigExtensionPreHydratedSelfReferentialWithoutId(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $extension = new LayoutTwigExtension($resolver);

        // Pre-hydrated array blocks with circular reference and no ID/UUID
        // Tests depth limit fallback when visited ID cannot be recorded
        $innerBlocks = [];
        $rootBlock = [
            'type' => 'reusable',
            'snippet' => [
                'blocks' => &$innerBlocks,
            ],
        ];
        $innerBlocks[] = $rootBlock;

        $layout = [
            'blocks' => [$rootBlock],
        ];

        $start = \microtime(true);
        $result = $extension->hasBlock($layout, 'target_block');
        $duration = (\microtime(true) - $start) * 1000;

        self::assertFalse($result);
        self::assertLessThan(100.0, $duration, 'Pre-hydrated cycle without ID must be stopped by MAX_RECURSION_DEPTH.');
    }

    public function testLayoutTwigExtensionDeepHierarchyRecursionLimit(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // Chain of 30 levels: Level 1 -> Level 2 -> ... -> Level 30
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if (1 === \preg_match('/^chain-(\d+)$/', $id, $matches)) {
                    $level = (int) $matches[1];
                    if ($level < 30) {
                        return [
                            'id' => $id,
                            'blocks' => [
                                ['type' => 'reusable', 'snippet' => 'chain-' . ($level + 1)],
                            ],
                        ];
                    }
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'chain-1'],
            ],
        ];

        // Searching for block absent in chain
        self::assertFalse($extension->hasBlock($layout, 'missing'));
    }

    public function testLayoutTwigExtensionRecursionDepthBoundaryCheck(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);

        // Test boundary: MAX_RECURSION_DEPTH = 10
        // Root depth is 0. Snippet 1 is depth 1 ... Snippet 10 is depth 10.
        // Level 10 snippet has depth 10 (10 <= 10 -> processed).
        // Level 11 snippet has depth 11 (11 > 10 -> rejected).

        $makeProviderWithTargetAtLevel = function (int $targetLevel): PageLayoutProviderInterface {
            $mock = $this->createStub(PageLayoutProviderInterface::class);
            $mock->method('getReusableBlock')
                ->willReturnCallback(static function (string $id) use ($targetLevel): array {
                    if (1 === \preg_match('/^depth-(\d+)$/', $id, $matches)) {
                        $level = (int) $matches[1];
                        if ($level === $targetLevel) {
                            return [
                                'id' => $id,
                                'blocks' => [
                                    ['type' => 'target_type', 'title' => 'Found'],
                                ],
                            ];
                        }
                        if ($level < 20) {
                            return [
                                'id' => $id,
                                'blocks' => [
                                    ['type' => 'reusable', 'snippet' => 'depth-' . ($level + 1)],
                                ],
                            ];
                        }
                    }

                    return [];
                });

            return $mock;
        };

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'depth-1'],
            ],
        ];

        // Depth 10: must be REACHED and found (10 <= 10)
        $ext10 = new LayoutTwigExtension($resolver, $makeProviderWithTargetAtLevel(10));
        self::assertTrue($ext10->hasBlock($layout, 'target_type'), 'Target at depth 10 must be found.');

        // Depth 11: must be CUT OFF by depth limit (11 > 10)
        $ext11 = new LayoutTwigExtension($resolver, $makeProviderWithTargetAtLevel(11));
        self::assertFalse($ext11->hasBlock($layout, 'target_type'), 'Target at depth 11 must be cut off by depth limit.');
    }

    public function testLayoutTwigExtensionDeepNestedSectionBlocks(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $extension = new LayoutTwigExtension($resolver);

        // Build nested section blocks: Section -> Rows -> Columns -> Section ...
        $buildNestedSections = static function (int $depth, string $leafType): array {
            $current = [
                'type' => $leafType,
                'title' => 'Leaf Block',
            ];

            for ($i = $depth; $i >= 1; --$i) {
                $current = [
                    'type' => 'section',
                    'rows' => [
                        [
                            'columns' => [
                                [
                                    'blocks' => [$current],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            return $current;
        };

        // 8 nested section levels with 'card' at the bottom (depth 8 <= 10): must be found
        $layout8 = ['blocks' => [$buildNestedSections(8, 'card')]];
        self::assertTrue($extension->hasBlock($layout8, 'card'));

        // 12 nested section levels with 'card' at the bottom (depth 12 > 10): must be cut off
        $layout12 = ['blocks' => [$buildNestedSections(12, 'card')]];
        self::assertFalse($extension->hasBlock($layout12, 'card'));
    }

    public function testLayoutTwigExtensionMixedSectionAndSnippetCycles(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // Section -> Reusable Snippet A -> Section -> Reusable Snippet B -> Reusable Snippet A
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'id' => 'snippet-A',
                        'blocks' => [
                            [
                                'type' => 'section',
                                'rows' => [
                                    [
                                        'columns' => [
                                            [
                                                'blocks' => [
                                                    ['type' => 'reusable', 'snippet' => 'snippet-B'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                if ('snippet-B' === $id) {
                    return [
                        'id' => 'snippet-B',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                [
                    'type' => 'section',
                    'rows' => [
                        [
                            'columns' => [
                                [
                                    'blocks' => [
                                        ['type' => 'reusable', 'snippet' => 'snippet-A'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        self::assertFalse($extension->hasBlock($layout, 'nonexistent_block'));
    }

    public function testLayoutTwigExtensionDiamondAcyclicGraphPattern(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $provider = $this->createStub(PageLayoutProviderInterface::class);

        // DAG: Root has [Snippet B, Snippet C]. Both Snippet B and Snippet C embed Snippet D.
        // Snippet D contains the target 'headline' block.
        $provider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-B' === $id) {
                    return [
                        'id' => 'snippet-B',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-D'],
                        ],
                    ];
                }
                if ('snippet-C' === $id) {
                    return [
                        'id' => 'snippet-C',
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-D'],
                        ],
                    ];
                }
                if ('snippet-D' === $id) {
                    return [
                        'id' => 'snippet-D',
                        'blocks' => [
                            ['type' => 'headline', 'title' => 'Diamond Target'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($resolver, $provider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-B'],
                ['type' => 'reusable', 'snippet' => 'snippet-C'],
            ],
        ];

        // Should return true via snippet-B -> snippet-D
        self::assertTrue($extension->hasBlock($layout, 'headline'));
    }

    public function testLayoutTwigExtensionMalformedBlockPayloads(): void
    {
        $resolver = $this->createStub(LayoutResolverInterface::class);
        $extension = new LayoutTwigExtension($resolver);

        $malformedBlocks = [
            null,
            123,
            'just a string',
            true,
            [],
            ['type' => null],
            ['type' => 12345],
            ['type' => 'reusable', 'snippet' => null],
            ['type' => 'reusable', 'snippet' => 999],
            ['type' => 'reusable', 'snippet' => []],
            ['type' => 'section', 'rows' => null],
            ['type' => 'section', 'rows' => 'not an array'],
            ['type' => 'section', 'rows' => [['columns' => null]]],
            ['type' => 'section', 'rows' => [['columns' => [['blocks' => null]]]]],
            ['type' => 'section', 'blocks' => 'not an array'],
        ];

        $layout = ['blocks' => $malformedBlocks];

        // Must evaluate gracefully without PHP warnings, errors, or exceptions
        self::assertFalse($extension->hasBlock($layout, 'card'));
    }

    public function testPageLayoutProviderRenderLayoutRecursionGuardSelfReference(): void
    {
        $twig = $this->createStub(Environment::class);
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('exists')->willReturn(true);
        $twig->method('getLoader')->willReturn($loader);

        /** @var array{provider: ?PageLayoutProvider} $holder */
        $holder = ['provider' => null];

        $twig->method('render')->willReturnCallback(static function () use (&$holder): string {
            if ($holder['provider'] instanceof PageLayoutProvider) {
                // Nested call with the same layout ID must trigger recursion guard and return empty string
                return 'RENDERED[' . $holder['provider']->renderLayout(['id' => 'nested_layout', 'blocks' => []]) . ']';
            }

            return '';
        });

        $provider = new PageLayoutProvider(null, $twig);
        $holder['provider'] = $provider;

        $output = $provider->renderLayout(['id' => 'nested_layout', 'blocks' => []]);

        self::assertSame('RENDERED[]', $output, 'Recursion guard must stop nested renderLayout call with same ID.');
    }
}
