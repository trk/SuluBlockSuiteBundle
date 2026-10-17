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
}
