<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\PresetBlockVisitor;

final class PresetBlockVisitorTest extends TestCase
{
    public function testVisitAppliesPresetPropsDefaultsAndPreservesExplicitOverrides(): void
    {
        $provider = $this->createStub(BlockPresetProviderInterface::class);
        $provider->method('getPresets')->willReturn([
            [
                'id' => 'hero_banner',
                'title' => 'Hero Banner',
                'description' => null,
                'preview_image' => null,
                'props' => [
                    'tag' => 'h1',
                    'size' => '2xl',
                    'title_color' => 'primary',
                ],
                'values' => [
                    'title' => 'Preset Default Title',
                ],
            ],
        ]);

        $visitor = new PresetBlockVisitor($provider);

        $block = [
            'type' => 'headline',
            'title' => 'Custom Editor Title',
            'props' => [
                'preset' => 'hero_banner',
                'title_color' => 'dark',
                'size' => '',
            ],
        ];

        $result = $visitor->visit($block);

        $this->assertSame('Custom Editor Title', $result['title']);
        $this->assertIsArray($result['props']);
        $this->assertSame('h1', $result['props']['tag']);
        $this->assertSame('2xl', $result['props']['size']);
        $this->assertSame('dark', $result['props']['title_color']);
    }
}
