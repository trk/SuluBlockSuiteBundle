<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolver;

final class LayoutResolverTest extends TestCase
{
    public function testResolveClassesWithDefaults(): void
    {
        $resolver = new LayoutResolver();
        $classes = $resolver->resolveClasses(null);

        $this->assertSame(LayoutResolver::DEFAULT_CONTAINER_CLASS, $classes['container']);
        $this->assertSame(LayoutResolver::DEFAULT_PADDING_CLASS, $classes['padding']);
        $this->assertSame('none', $classes['sidebar']);
        $this->assertSame('solid', $classes['header_variant']);
    }

    public function testResolveClassesWithCustomMappings(): void
    {
        $resolver = new LayoutResolver([
            'container_width' => [
                'full' => 'w-full',
                'boxed' => 'container-boxed',
            ],
            'content_padding' => [
                'compact' => 'py-4',
            ],
            'background' => [
                'dark' => 'bg-neutral-900 text-white',
                'default' => 'bg-white text-gray-900',
            ],
        ]);

        $classes = $resolver->resolveClasses([
            'container_width' => 'full',
            'content_padding' => 'compact',
            'sidebar' => 'left',
            'header_variant' => 'transparent',
            'background' => 'dark',
        ]);

        $this->assertSame('w-full', $classes['container']);
        $this->assertSame('py-4', $classes['padding']);
        $this->assertSame('left', $classes['sidebar']);
        $this->assertSame('transparent', $classes['header_variant']);
        $this->assertSame('bg-neutral-900 text-white', $classes['background']);
    }

    public function testResolveClassSingle(): void
    {
        $resolver = new LayoutResolver([
            'theme' => [
                'modern' => 'theme-modern',
                'default' => 'theme-default',
            ],
        ]);

        $this->assertSame('theme-modern', $resolver->resolveClass('theme', 'modern'));
        $this->assertSame('theme-default', $resolver->resolveClass('theme', 'non-existing'));
        $this->assertSame('theme-default', $resolver->resolveClass('theme', null));
        $this->assertSame('fallback', $resolver->resolveClass('unknown', null, 'fallback'));
    }

    public function testResolveAttributes(): void
    {
        $resolver = new LayoutResolver();
        $attrs = $resolver->resolveAttributes([
            'container_width' => 'boxed',
            'sidebar' => 'right',
            'header_variant' => 'minimal',
            'content_padding' => 'compact',
            'custom_theme' => 'dark',
        ]);

        $this->assertSame('boxed', $attrs['data-layout-container-width']);
        $this->assertSame('right', $attrs['data-layout-sidebar']);
        $this->assertSame('minimal', $attrs['data-layout-header-variant']);
        $this->assertSame('compact', $attrs['data-layout-content-padding']);
        $this->assertSame('dark', $attrs['data-layout-custom-theme']);
    }

    public function testResolveWrapperClassesWithDefaults(): void
    {
        $resolver = new LayoutResolver();
        $this->assertSame('', $resolver->resolveWrapperClasses(null));
        $this->assertSame('base-class', $resolver->resolveWrapperClasses(null, 'base-class'));

        $classes = $resolver->resolveWrapperClasses([
            'margin_top' => 'md',
            'margin_bottom' => 'lg',
            'max_width' => 'large',
            'block_align' => 'center',
            'custom_class' => 'my-custom-wrapper',
        ], 'extra-block-class');

        $this->assertStringContainsString('mt-6', $classes);
        $this->assertStringContainsString('mb-10', $classes);
        $this->assertStringContainsString('max-w-5xl mx-auto', $classes);
        $this->assertStringContainsString('text-center mx-auto', $classes);
        $this->assertStringContainsString('my-custom-wrapper', $classes);
        $this->assertStringContainsString('extra-block-class', $classes);
    }

    public function testResolveWrapperClassesWithCustomMappings(): void
    {
        $resolver = new LayoutResolver([
            'margin_top' => ['sm' => 'custom-mt-2'],
            'max_width' => ['full' => 'w-full'],
        ]);

        $classes = $resolver->resolveWrapperClasses([
            'margin_top' => 'sm',
            'max_width' => 'full',
        ]);

        $this->assertSame('custom-mt-2 w-full', $classes);
    }
}
