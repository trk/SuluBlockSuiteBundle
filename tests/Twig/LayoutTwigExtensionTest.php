<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Twig\LayoutTwigExtension;

final class LayoutTwigExtensionTest extends TestCase
{
    public function testGetClassesDelegatesToResolver(): void
    {
        $mockResolver = $this->createMock(LayoutResolverInterface::class);
        $mockResolver->expects($this->once())
            ->method('resolveClasses')
            ->with(['layout_width' => 'full'])
            ->willReturn(['layout_width' => 'w-full']);

        $extension = new LayoutTwigExtension($mockResolver);
        $this->assertSame(['layout_width' => 'w-full'], $extension->getClasses(['layout_width' => 'full']));
    }

    public function testGetAttributesFormatsHtmlString(): void
    {
        $mockResolver = $this->createMock(LayoutResolverInterface::class);
        $mockResolver->expects($this->once())
            ->method('resolveAttributes')
            ->willReturn([
                'data-layout-width' => 'boxed',
                'data-layout-sidebar' => 'left',
            ]);

        $extension = new LayoutTwigExtension($mockResolver);
        $html = $extension->getAttributes([]);

        $this->assertStringContainsString('data-layout-width="boxed"', $html);
        $this->assertStringContainsString('data-layout-sidebar="left"', $html);
    }

    public function testRenderLayoutDelegatesToProvider(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $mockProvider = $this->createMock(PageLayoutProviderInterface::class);
        $mockProvider->expects($this->once())
            ->method('renderLayout')
            ->with('default', ['extra' => 123], 'en')
            ->willReturn('<div>Rendered</div>');

        $extension = new LayoutTwigExtension($stubResolver, $mockProvider);
        $output = $extension->renderLayout('default', ['extra' => 123], 'en');

        $this->assertSame('<div>Rendered</div>', $output);
    }

    public function testLoadLayoutDelegatesToProvider(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $mockProvider = $this->createMock(PageLayoutProviderInterface::class);
        $mockProvider->expects($this->once())
            ->method('getLayout')
            ->with('default', 'en')
            ->willReturn(['title' => 'Default', 'blocks' => []]);

        $extension = new LayoutTwigExtension($stubResolver, $mockProvider);
        $data = $extension->loadLayout('default', 'en');

        $this->assertSame(['title' => 'Default', 'blocks' => []], $data);
    }

    public function testHasBlockReturnsTrueWhenBlockPresent(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $extension = new LayoutTwigExtension($stubResolver);

        $layout = [
            'blocks' => [
                ['type' => 'headline', 'title' => 'Title'],
                ['type' => 'card', 'title' => 'Card'],
            ],
        ];

        $this->assertTrue($extension->hasBlock($layout, 'card'));
        $this->assertFalse($extension->hasBlock($layout, 'accordion'));
    }

    public function testHasBlockGuardsAgainstCircularReferencesInReusableBlocks(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);

        // Cyclic reference: snippet-1 -> snippet-2 -> snippet-1
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if ('snippet-1' === $id) {
                    return [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-2'],
                        ],
                    ];
                }
                if ('snippet-2' === $id) {
                    return [
                        'blocks' => [
                            ['type' => 'reusable', 'snippet' => 'snippet-1'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'snippet-1'],
            ],
        ];

        // Without protection, this enters infinite recursion and crashes PHP.
        // With protection, it cleanly returns false.
        $this->assertFalse($extension->hasBlock($layout, 'target_block'));
    }

    public function testHasBlockRespectsMaxRecursionDepthLimit(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);

        // Deep chain of 15 levels: depth 1 -> depth 2 -> ... -> depth 15 (which contains 'target_block')
        $mockProvider->method('getReusableBlock')
            ->willReturnCallback(static function (string $id): array {
                if (1 === \preg_match('/^level-(\d+)$/', $id, $matches)) {
                    $level = (int) $matches[1];
                    if ($level < 15) {
                        return [
                            'blocks' => [
                                ['type' => 'reusable', 'snippet' => 'level-' . ($level + 1)],
                            ],
                        ];
                    }

                    return [
                        'blocks' => [
                            ['type' => 'target_block'],
                        ],
                    ];
                }

                return [];
            });

        $extension = new LayoutTwigExtension($stubResolver, $mockProvider);

        $layout = [
            'blocks' => [
                ['type' => 'reusable', 'snippet' => 'level-1'],
            ],
        ];

        // Max depth is 10, so level-15 should NOT be reached and should return false
        $this->assertFalse($extension->hasBlock($layout, 'target_block'));
    }

    public function testHasBlockTraversesSectionBlocksWithNestedRowsAndColumns(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $extension = new LayoutTwigExtension($stubResolver);

        $layout = [
            'blocks' => [
                [
                    'type' => 'section',
                    'rows' => [
                        [
                            'columns' => [
                                [
                                    'blocks' => [
                                        ['type' => 'card', 'title' => 'Nested Card'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTrue($extension->hasBlock($layout, 'card'));
        $this->assertFalse($extension->hasBlock($layout, 'accordion'));
    }

    public function testHasLayoutResolvesActiveWebspaceFromRequestWhenWebspaceKeyOmitted(): void
    {
        $stubResolver = $this->createStub(LayoutResolverInterface::class);
        $mockProvider = $this->createStub(PageLayoutProviderInterface::class);
        $mockProvider->method('getLayout')->willReturn(null);

        $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->attributes->set('webspaceKey', 'totoglu');
        $requestStack->push($request);

        $extension = new LayoutTwigExtension($stubResolver, $mockProvider, $requestStack);

        $foreignWebspaceInlineLayout = [
            'webspace' => 'turkiye',
            'blocks' => [
                ['type' => 'headline', 'title' => 'Turkiye Header'],
            ],
        ];

        $matchingWebspaceInlineLayout = [
            'webspace' => 'totoglu',
            'blocks' => [
                ['type' => 'headline', 'title' => 'Totoglu Header'],
            ],
        ];

        self::assertFalse($extension->hasLayout($foreignWebspaceInlineLayout));
        self::assertTrue($extension->hasLayout($matchingWebspaceInlineLayout));
    }
}
