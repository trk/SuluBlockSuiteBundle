<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Content\Type;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Content\Type\HotspotPickerPropertyResolver;

final class HotspotPickerPropertyResolverTest extends TestCase
{
    private HotspotPickerPropertyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new HotspotPickerPropertyResolver();
    }

    public function testGetType(): void
    {
        self::assertSame('hotspot_picker', HotspotPickerPropertyResolver::getType());
    }

    public function testResolveWithArray(): void
    {
        $data = [
            'x' => 35.4,
            'y' => 62.8,
            'marker_type' => 'plus',
            'marker_color' => 'dark',
            'marker_icon' => 'fa-plus',
            'trigger' => 'hover',
        ];

        $result = $this->resolver->resolve($data, 'en');

        self::assertSame([
            'x' => 35.4,
            'y' => 62.8,
            'marker_type' => 'plus',
            'marker_color' => 'dark',
            'marker_icon' => 'fa-plus',
            'trigger' => 'hover',
        ], $result->getContent());
    }

    public function testResolveWithJsonStringAndSchemaParams(): void
    {
        $json = '{"x": 20.5, "y": 80.2, "marker_type": "number"}';
        $params = [
            'types' => ['pulse' => 'pulse', 'number' => 'number'],
            'colors' => ['primary' => 'primary', 'dark' => 'dark'],
            'triggers' => ['click' => 'click', 'hover' => 'hover'],
        ];
        $result = $this->resolver->resolve($json, 'en', $params);

        /** @var array<string, mixed> $content */
        $content = $result->getContent();

        self::assertSame(20.5, $content['x']);
        self::assertSame(80.2, $content['y']);
        self::assertSame('number', $content['marker_type']);
        self::assertSame('primary', $content['marker_color']);
        self::assertSame('click', $content['trigger']);
    }

    public function testResolveClampingAndSchemaDefaults(): void
    {
        $data = [
            'x' => 150.0,
            'y' => -20.0,
            'marker_type' => 'unknown',
        ];
        $params = [
            'types' => ['dot', 'pin'],
            'colors' => 'accent,success',
            'triggers' => ['hover', 'click'],
        ];

        $result = $this->resolver->resolve($data, 'en', $params);

        /** @var array<string, mixed> $content */
        $content = $result->getContent();

        self::assertSame(100.0, $content['x']);
        self::assertSame(0.0, $content['y']);
        self::assertSame('dot', $content['marker_type']);
        self::assertSame('accent', $content['marker_color']);
        self::assertNull($content['marker_icon']);
        self::assertSame('hover', $content['trigger']);
    }

    public function testResolveWithEmptyDataAndNoParams(): void
    {
        $result = $this->resolver->resolve(null, 'en');

        /** @var array<string, mixed> $content */
        $content = $result->getContent();

        self::assertSame(50.0, $content['x']);
        self::assertSame(50.0, $content['y']);
        self::assertNull($content['marker_type']);
        self::assertNull($content['marker_color']);
        self::assertNull($content['marker_icon']);
        self::assertNull($content['trigger']);
    }
}
