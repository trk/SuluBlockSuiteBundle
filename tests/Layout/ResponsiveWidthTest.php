<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\ResponsiveWidth;

final class ResponsiveWidthTest extends TestCase
{
    public function testContainerScaleDivideCapAndToSizes(): void
    {
        $slot = ResponsiveWidth::container(1280)
            ->divide(['base' => 1, 'md' => 2, 'lg' => 4]);

        self::assertSame(640, $slot->slotPx('base'));
        self::assertSame(768, $slot->slotPx('sm'));
        self::assertSame(512, $slot->slotPx('md'));
        self::assertSame(320, $slot->slotPx('lg'));
        self::assertSame(320, $slot->slotPx('2xl'));
        self::assertSame(768, $slot->maxSlotPx());

        $sizes = $slot->toSizes();
        self::assertStringContainsString('(min-width: 1280px) 320px', $sizes);
        self::assertStringContainsString('(min-width: 1024px) 25vw', $sizes);
        self::assertStringContainsString('(min-width: 768px) 50vw', $sizes);
        self::assertStringEndsWith('100vw', $sizes);

        $restored = ResponsiveWidth::fromArray($slot->toArray());
        self::assertNotNull($restored);
        self::assertSame($sizes, $restored->toSizes());
    }
}
