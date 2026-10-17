<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolver;

final class LayoutResolverTest extends TestCase
{
    public function testResolveClassesWithEmptyDefaults(): void
    {
        $resolver = new LayoutResolver();
        $classes = $resolver->resolveClasses(null);

        self::assertSame([], $classes);
    }

    public function testResolveClassesWithConfiguredDefaults(): void
    {
        $resolver = new LayoutResolver([], [
            'sidebar' => 'none',
            'header_variant' => 'solid',
            'layout_width' => 'boxed',
            'layout_spacing' => 'normal',
        ]);

        $classes = $resolver->resolveClasses(null);

        self::assertSame('none', $classes['sidebar']);
        self::assertSame('solid', $classes['header_variant']);
        self::assertSame('boxed', $classes['layout_width']);
        self::assertSame('normal', $classes['layout_spacing']);

        $overridden = $resolver->resolveClasses([
            'sidebar' => 'left',
            'header_variant' => 'transparent',
            'blocks' => [['type' => 'fixture_block']],
            'title' => 'Ignored Structural Title',
        ]);

        self::assertSame('left', $overridden['sidebar']);
        self::assertSame('transparent', $overridden['header_variant']);
        self::assertSame('boxed', $overridden['layout_width']);
        self::assertSame('normal', $overridden['layout_spacing']);
        self::assertArrayNotHasKey('blocks', $overridden);
        self::assertArrayNotHasKey('title', $overridden);
    }

    public function testResolveClassesWithCustomMappingsAndDefaults(): void
    {
        $resolver = new LayoutResolver(
            [
                'layout_width' => [
                    'full' => 'w-full',
                    'boxed' => 'container-boxed',
                ],
                'layout_spacing' => [
                    'compact' => 'py-4',
                    'normal' => 'py-8',
                ],
                'surface' => [
                    'dark' => 'surface-dark',
                    'default' => 'surface-light',
                ],
            ],
            [
                'sidebar' => 'none',
                'header_variant' => 'solid',
                'layout_width' => 'boxed',
                'layout_spacing' => 'normal',
            ],
        );

        $defaultClasses = $resolver->resolveClasses(null);
        self::assertSame('container-boxed', $defaultClasses['layout_width']);
        self::assertSame('py-8', $defaultClasses['layout_spacing']);
        self::assertSame('none', $defaultClasses['sidebar']);
        self::assertSame('solid', $defaultClasses['header_variant']);
        self::assertSame('surface-light', $defaultClasses['surface']);

        $customClasses = $resolver->resolveClasses([
            'layout_width' => 'full',
            'layout_spacing' => 'compact',
            'sidebar' => 'left',
            'header_variant' => 'transparent',
            'surface' => 'dark',
        ]);

        self::assertSame('w-full', $customClasses['layout_width']);
        self::assertSame('py-4', $customClasses['layout_spacing']);
        self::assertSame('left', $customClasses['sidebar']);
        self::assertSame('transparent', $customClasses['header_variant']);
        self::assertSame('surface-dark', $customClasses['surface']);
    }

    public function testResolveClassSingle(): void
    {
        $resolver = new LayoutResolver(
            [
                'theme' => [
                    'modern' => 'theme-modern',
                    'default' => 'theme-default',
                ],
                'density' => [
                    'compact' => 'density-compact',
                    'comfortable' => 'density-comfortable',
                ],
            ],
            [
                'density' => 'comfortable',
            ],
        );

        self::assertSame('theme-modern', $resolver->resolveClass('theme', 'modern'));
        self::assertSame('theme-default', $resolver->resolveClass('theme', 'non-existing'));
        self::assertSame('theme-default', $resolver->resolveClass('theme', null));
        self::assertSame('density-comfortable', $resolver->resolveClass('density', null));
        self::assertSame('density-comfortable', $resolver->resolveClass('density', 'unknown'));
        self::assertSame('fallback', $resolver->resolveClass('unknown', null, 'fallback'));
    }

    public function testResolveAttributes(): void
    {
        $resolver = new LayoutResolver([], [
            'sidebar' => 'none',
        ]);

        self::assertSame(['data-layout-sidebar' => 'none'], $resolver->resolveAttributes(null));

        $attrs = $resolver->resolveAttributes([
            'layout_width' => 'boxed',
            'sidebar' => 'right',
            'header_variant' => 'minimal',
            'layout_spacing' => 'compact',
            'custom_theme' => 'dark',
            'title' => 'Ignored',
            'blocks' => [],
        ]);

        self::assertSame('boxed', $attrs['data-layout-layout-width']);
        self::assertSame('right', $attrs['data-layout-sidebar']);
        self::assertSame('minimal', $attrs['data-layout-header-variant']);
        self::assertSame('compact', $attrs['data-layout-layout-spacing']);
        self::assertSame('dark', $attrs['data-layout-custom-theme']);
        self::assertArrayNotHasKey('data-layout-title', $attrs);
        self::assertArrayNotHasKey('data-layout-blocks', $attrs);
    }
}
