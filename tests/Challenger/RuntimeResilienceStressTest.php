<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Challenger;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\DefaultAttributesTransformer;
use Totoglu\SuluBlockSuiteBundle\Twig\LayoutTwigExtension;

final class RuntimeResilienceStressTest extends TestCase
{
    private LayoutResolverInterface $stubResolver;

    protected function setUp(): void
    {
        $this->stubResolver = $this->createStub(LayoutResolverInterface::class);
    }

    // =========================================================================
    // SECTION 1: LayoutTwigExtension::blocksContainType() Cyclic & Nested Graphs
    // =========================================================================

    public function testDirectSelfReferencingReusableBlockAtoA(): void
    {
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-A'],
            ],
        ];

        // Should return false and not loop infinitely
        $this->assertFalse($extension->hasBlock($layout, 'target_type'));
    }

    public function testMutualCyclicReusableBlocksAtoBtoA(): void
    {
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-A' === $id) {
                    return [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-B'],
                        ],
                    ];
                }
                if ('snippet-B' === $id) {
                    return [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-A'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-A'],
            ],
        ];

        $this->assertFalse($extension->hasBlock($layout, 'non_existent'));
    }

    public function testThreeNodeCycleAtoBtoCtoA(): void
    {
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                return match ($id) {
                    'node-A' => ['blocks' => [['type' => 'reusable', 'snippet' => 'node-B']]],
                    'node-B' => ['blocks' => [['type' => 'reusable', 'snippet' => 'node-C']]],
                    'node-C' => ['blocks' => [['type' => 'reusable', 'snippet' => 'node-A']]],
                    default => [],
                };
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'node-A'],
            ],
        ];

        $this->assertFalse($extension->hasBlock($layout, 'missing'));
    }

    public function testCyclicGraphWithTargetBlockAtTail(): void
    {
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                return match ($id) {
                    'node-A' => [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'node-B'],
                        ],
                    ],
                    'node-B' => [
                        'blocks' => [
                            // Cycle back to A
                            ['type' => 'reusable', 'snippet' => 'node-A'],
                            // But also target block!
                            ['type' => 'wanted_block'],
                        ],
                    ],
                    default => [],
                };
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'node-A'],
            ],
        ];

        // Should successfully find 'wanted_block' in node-B even though node-B cycles back to node-A
        $this->assertTrue($extension->hasBlock($layout, 'wanted_block'));
    }

    public function testArraySnippetSelfReferencingWithId(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        $layout = [
            'blocks' => [
                [
                    'type' => 'reusable',
                    'snippet' => [
                        'id' => 'snippet-self',
                        'blocks' => [
                            [
                                'type' => 'reusable',
                                'snippet' => [
                                    'id' => 'snippet-self',
                                    'blocks' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertFalse($extension->hasBlock($layout, 'target'));
    }

    public function testAnonymousArraySnippetWithoutIdStopsAtMaxDepth(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        // Build 20 nested anonymous reusable snippets (no ID/UUID)
        $innermost = ['type' => 'target_type'];
        $current = $innermost;
        for ($i = 20; $i >= 1; --$i) {
            $current = [
                'type' => 'reusable',
                'snippet' => [
                    'blocks' => [$current],
                ],
            ];
        }

        $layout = ['blocks' => [$current]];

        // Because nesting is 20 and MAX_RECURSION_DEPTH is 10, target_type at depth 20 cannot be reached
        $this->assertFalse($extension->hasBlock($layout, 'target_type'));
    }

    public function testAnonymousArraySnippetWithinMaxDepthIsFound(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        // Build 5 nested anonymous reusable snippets (depth 5 < 10)
        $innermost = ['type' => 'target_type'];
        $current = $innermost;
        for ($i = 5; $i >= 1; --$i) {
            $current = [
                'type' => 'reusable',
                'snippet' => [
                    'blocks' => [$current],
                ],
            ];
        }

        $layout = ['blocks' => [$current]];

        $this->assertTrue($extension->hasBlock($layout, 'target_type'));
    }

    public function testInMemorySelfReferencingReferenceLoopStopsAtMaxDepth(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        // Create cyclic reference in memory
        $cyclicBlock = [
            'type' => 'reusable',
            'snippet' => [
                'blocks' => [],
            ],
        ];
        $cyclicBlock['snippet']['blocks'][] = &$cyclicBlock;

        $layout = ['blocks' => [$cyclicBlock]];

        // Must not crash or exhaust memory, should terminate at depth 10
        $this->assertFalse($extension->hasBlock($layout, 'target'));
    }

    public function testDeepNestingChain100Levels(): void
    {
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if (1 === \preg_match('/^depth-(\d+)$/', $id, $matches)) {
                    $lvl = (int) $matches[1];
                    if ($lvl < 100) {
                        return [
                            'blocks' => [
                                ['type' => 'reusable', 'snippet' => 'depth-' . ($lvl + 1)],
                            ],
                        ];
                    }

                    return ['blocks' => [['type' => 'deep_block']]];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'depth-1'],
            ],
        ];

        // 100 levels deep: safely stops at max recursion depth 10
        $this->assertFalse($extension->hasBlock($layout, 'deep_block'));
    }

    public function testMalformedBlocksInArrayDoNotThrow(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        $layout = [
            'blocks' => [
                null,
                123,
                'invalid-string',
                false,
                true,
                [],
                ['type' => null],
                ['type' => 12345],
                ['type' => ['array_type']],
                ['type' => 'reusable', 'snippet' => null],
                ['type' => 'reusable', 'snippet' => 123],
                ['type' => 'reusable', 'snippet' => false],
                ['type' => 'reusable', 'snippet' => ['blocks' => 'not-an-array']],
                ['type' => 'target'],
            ],
        ];

        $this->assertTrue($extension->hasBlock($layout, 'target'));
        $this->assertFalse($extension->hasBlock($layout, 'missing'));
    }

    public function testDiamondReusableGraphSearchesCorrectly(): void
    {
        // Root -> A and B. Both A and B reference C. C contains target.
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                return match ($id) {
                    'A' => ['blocks' => [['type' => 'reusable', 'snippet' => 'C']]],
                    'B' => ['blocks' => [['type' => 'reusable', 'snippet' => 'C']]],
                    'C' => ['blocks' => [['type' => 'target_in_c']]],
                    default => [],
                };
            });

        $extension = new LayoutTwigExtension($this->stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'A'],
                ['type' => 'reusable', 'snippet' => 'B'],
            ],
        ];

        $this->assertTrue($extension->hasBlock($layout, 'target_in_c'));
    }

    public function testExecutionPerformanceUnderLargeBlockCollection(): void
    {
        $extension = new LayoutTwigExtension($this->stubResolver);

        // Build 5,000 blocks to verify O(N) performance and memory stability
        $blocks = [];
        for ($i = 0; $i < 5000; ++$i) {
            $blocks[] = [
                'type' => 'text',
                'title' => 'Item ' . $i,
            ];
        }
        $blocks[] = ['type' => 'needle_block'];

        $startTime = \microtime(true);
        $startMem = \memory_get_usage();

        $found = $extension->hasBlock(['blocks' => $blocks], 'needle_block');
        $notFound = $extension->hasBlock(['blocks' => $blocks], 'missing_needle');

        $elapsed = \microtime(true) - $startTime;
        $memDelta = \memory_get_usage() - $startMem;

        $this->assertTrue($found);
        $this->assertFalse($notFound);
        $this->assertLessThan(0.1, $elapsed, 'Searching 5,000 blocks took more than 100ms');
        $this->assertLessThan(1024 * 1024, $memDelta, 'Memory delta exceeded 1MB');
    }

    // =========================================================================
    // SECTION 2: DefaultAttributesTransformer Complex Shapes & Data Integrity
    // =========================================================================

    public function testTransformerWithArbitraryArrayShapesAndTypes(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'complex_block',
            'props' => [
                'name' => 'Complex Test',
                'id' => 'complex-id',
                'class' => 'p-4 m-2',
            ],
            // Non-candidate keys
            '_custom_cache' => ['any' => 'data'],
            'props/raw' => 'raw-data',
            'custom_url' => 'https://example.com/already_resolved', // Ends with _url, skipped as candidate
            // Candidate keys with non-string, non-media, non-link values
            'numeric_list' => [1, 2, 3, 4],
            'assoc_data' => ['nested' => 'value', 'count' => 42],
            'integer_val' => 999,
            'bool_val' => true,
            'empty_array' => [],
            'null_val' => null,
            // Candidate keys with URL-like strings
            'website' => 'https://example.com',
            'article_link' => '/posts/hello-world',
            'anchor_link' => '#comments',
            'query_link' => '?filter=active',
            'protocol_relative' => '//cdn.example.com/lib.js',
            'phone' => 'tel:+1234567890',
            'email' => 'mailto:info@example.com',
            'sms_link' => 'sms:+1234567890',
        ];

        $transformed = $transformer->transform($block, []);

        // Verify preserved original keys
        $this->assertSame([1, 2, 3, 4], $transformed['numeric_list']);
        $this->assertSame(['nested' => 'value', 'count' => 42], $transformed['assoc_data']);
        $this->assertSame(999, $transformed['integer_val']);
        $this->assertTrue($transformed['bool_val']);
        $this->assertSame([], $transformed['empty_array']);
        $this->assertNull($transformed['null_val']);
        $this->assertSame(['any' => 'data'], $transformed['_custom_cache']);

        // Verify resolved and sanitized URLs
        $this->assertSame('https://example.com', $transformed['website']);
        $this->assertSame('https://example.com', $transformed['website_url']);
        $this->assertSame('/posts/hello-world', $transformed['article_link']);
        $this->assertSame('/posts/hello-world', $transformed['article_link_url']);
        $this->assertSame('#comments', $transformed['anchor_link']);
        $this->assertSame('#comments', $transformed['anchor_link_url']);
        $this->assertSame('?filter=active', $transformed['query_link']);
        $this->assertSame('?filter=active', $transformed['query_link_url']);
        $this->assertSame('//cdn.example.com/lib.js', $transformed['protocol_relative']);
        $this->assertSame('//cdn.example.com/lib.js', $transformed['protocol_relative_url']);
        $this->assertSame('tel:+1234567890', $transformed['phone']);
        $this->assertSame('tel:+1234567890', $transformed['phone_url']);
        $this->assertSame('mailto:info@example.com', $transformed['email']);
        $this->assertSame('mailto:info@example.com', $transformed['email_url']);
    }

    public function testTransformerRejectsDangerousUrlSchemesAcrossLinkFields(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $dangerousInputs = [
            'js_plain_link' => 'javascript:alert(1)',
            'js_mixed_case_link' => 'JaVaScRiPt:alert(1)',
            'js_tab_link' => "java\tscript:alert(1)",
            'js_newline_link' => "java\nscript:alert(1)",
            'js_encoded_link' => '&#x6A;avascript:alert(1)',
            'js_double_encoded_link' => '&amp;#x6A;avascript:alert(1)',
            'js_null_byte_link' => "java\0script:alert(1)",
            'js_zero_width_link' => "java\u{200B}script:alert(1)",
            'vbscript_link' => 'vbscript:msgbox(1)',
            'data_html_link' => 'data:text/html;base64,PHNjcmlwdD4=',
            'file_scheme_link' => 'file:///etc/passwd',
            'blob_scheme_link' => 'blob:https://example.com/uuid',
            'disallowed_ftp_link' => 'ftp://files.example.com/test',
            'disallowed_ssh_link' => 'ssh://git@github.com',
            'disallowed_gopher_link' => 'gopher://example.com',
        ];

        $block = \array_merge(['type' => 'card'], $dangerousInputs);
        $transformed = $transformer->transform($block, []);

        foreach (\array_keys($dangerousInputs) as $key) {
            $this->assertSame('#', $transformed[$key], "Field {$key} was not sanitized to fallback '#'");
            $this->assertSame('#', $transformed[$key . '_url'], "Field {$key}_url was not sanitized to fallback '#'");
        }
    }

    public function testTransformerWithNestedArrayLinksAndExtraMetadata(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'cta_block',
            'primary_button_link' => [
                'url' => 'https://example.com/dashboard',
                'target' => '_blank',
                'title' => 'Dashboard Link',
                'anchor' => 'stats',
                'attributes' => ['data-track' => 'cta-1'],
            ],
            'secondary_button_link' => [
                'href' => '/contact-us',
                'rel' => 'nofollow',
            ],
            'malicious_array_link' => [
                'url' => 'javascript:alert(document.cookie)',
                'target' => '_self',
                'title' => 'Hacked',
            ],
            'malicious_href_array' => [
                'href' => 'data:text/html,<script>alert(1)</script>',
                'title' => 'XSS',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        // Safe URL with anchor appended in _url
        $this->assertSame('https://example.com/dashboard#stats', $transformed['primary_button_link_url']);

        $primaryLink = $transformed['primary_button_link'];
        $this->assertIsArray($primaryLink);
        // Verify array integrity: target, title, attributes are preserved
        $this->assertSame('_blank', $primaryLink['target']);
        $this->assertSame('Dashboard Link', $primaryLink['title']);
        $this->assertSame(['data-track' => 'cta-1'], $primaryLink['attributes']);
        $this->assertSame('https://example.com/dashboard', $primaryLink['url']);

        // Safe href
        $this->assertSame('/contact-us', $transformed['secondary_button_link_url']);
        $secondaryLink = $transformed['secondary_button_link'];
        $this->assertIsArray($secondaryLink);
        $this->assertSame('nofollow', $secondaryLink['rel']);

        // Malicious url sanitized in both places
        $this->assertSame('#', $transformed['malicious_array_link_url']);
        $maliciousLink = $transformed['malicious_array_link'];
        $this->assertIsArray($maliciousLink);
        $this->assertSame('#', $maliciousLink['url']);
        $this->assertSame('_self', $maliciousLink['target']);
        $this->assertSame('Hacked', $maliciousLink['title']);

        // Malicious href sanitized
        $this->assertSame('#', $transformed['malicious_href_array_url']);
        $maliciousHref = $transformed['malicious_href_array'];
        $this->assertIsArray($maliciousHref);
        $this->assertSame('#', $maliciousHref['href']);
    }

    public function testConflictingLinkAndLinkUrlBothSanitized(): void
    {
        $transformer = new DefaultAttributesTransformer();

        // Scenario 1: 'link' is safe, 'link_url' is malicious
        $block1 = [
            'type' => 'card',
            'link' => 'https://example.com/safe',
            'link_url' => 'javascript:evil()',
        ];
        $transformed1 = $transformer->transform($block1, []);
        $this->assertSame('https://example.com/safe', $transformed1['link']);
        $this->assertSame('#', $transformed1['link_url']);

        // Scenario 2: 'link' is malicious, 'link_url' is safe
        $block2 = [
            'type' => 'card',
            'link' => 'javascript:evil()',
            'link_url' => 'https://example.com/safe',
        ];
        $transformed2 = $transformer->transform($block2, []);
        $this->assertSame('#', $transformed2['link']);
        $this->assertSame('https://example.com/safe', $transformed2['link_url']);
    }

    public function testTransformerHandlesNonStringUrlsInsideArrayGracefully(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            // Arrays with 'target' key are detected as 'link' candidates
            'link_with_nested_array' => [
                'url' => ['nested' => 'array'],
                'target' => '_blank',
            ],
            'link_with_int' => [
                'url' => 12345,
                'target' => '_blank',
            ],
            'link_with_null' => [
                'url' => null,
                'target' => '_blank',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        // None of these should throw TypeError or notice; fallback '#' must be assigned to *_url
        $this->assertSame('#', $transformed['link_with_nested_array_url']);
        $this->assertSame('#', $transformed['link_with_int_url']);
        $this->assertSame('#', $transformed['link_with_null_url']);
    }

    public function testTransformerWithDisabledBlockShortCircuits(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'status' => 'disabled',
            'link' => 'javascript:alert(1)',
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertTrue($transformed['_hidden']);
        // When disabled, short-circuit happens before link normalization
        $this->assertArrayNotHasKey('link_url', $transformed);
    }
}
