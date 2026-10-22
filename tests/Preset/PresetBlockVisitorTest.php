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

    public function testVisitHydratesNestedSectionRowsWhenEmptyAndPreservesExplicitRows(): void
    {
        $presetRows = [
            [
                'type' => 'row_item',
                'props' => ['layout' => '6-6'],
                'columns' => [
                    [
                        'type' => 'column_item',
                        'props' => ['col_span' => '6'],
                        'blocks' => [
                            ['type' => 'headline', 'title' => 'Hero Form Title'],
                            ['type' => 'form', 'form' => 2],
                        ],
                    ],
                ],
            ],
        ];

        $provider = $this->createStub(BlockPresetProviderInterface::class);
        $provider->method('getPresets')->willReturn([
            [
                'id' => 'hero_single_email_input',
                'title' => 'Hero Form Preset',
                'description' => null,
                'preview_image' => '/presets/wireframes/hero-single-email.svg',
                'props' => [
                    'max_width' => '85rem',
                    'padding' => 'lg',
                ],
                'values' => [
                    'rows' => $presetRows,
                ],
            ],
        ]);

        $visitor = new PresetBlockVisitor($provider);

        $emptySection = [
            'type' => 'section',
            'rows' => [],
            'props/preset' => 'hero_single_email_input',
        ];

        $hydrated = $visitor->visit($emptySection);
        $this->assertSame($presetRows, $hydrated['rows']);
        $this->assertIsArray($hydrated['props']);
        $this->assertSame('lg', $hydrated['props']['padding']);

        $customRows = [
            ['type' => 'row_item', 'columns' => []],
        ];
        $customSection = [
            'type' => 'section',
            'rows' => $customRows,
            'props' => ['preset' => 'hero_single_email_input'],
        ];

        $preserved = $visitor->visit($customSection);
        $this->assertSame($customRows, $preserved['rows']);
    }

    public function testVisitRecursivelyHydratesChildBlockPresetsInsideSectionRows(): void
    {
        $provider = $this->createStub(BlockPresetProviderInterface::class);
        $provider->method('getPresets')->willReturnCallback(
            static function (string $blockType): array {
                if ('card' === $blockType) {
                    return [
                        [
                            'id' => 'feature_card_preset',
                            'title' => 'Feature Card Preset',
                            'description' => null,
                            'preview_image' => null,
                            'props' => [
                                'variant' => 'elevated',
                                'padding' => 'lg',
                            ],
                            'values' => [
                                'title' => 'Default Preset Card Title',
                                'text' => '<p>Default Preset Card Body</p>',
                            ],
                        ],
                    ];
                }

                return [];
            },
        );

        $visitor = new PresetBlockVisitor($provider);

        $sectionBlock = [
            'type' => 'section',
            'rows' => [
                [
                    'type' => 'row_item',
                    'columns' => [
                        [
                            'type' => 'column_item',
                            'blocks' => [
                                [
                                    'type' => 'card',
                                    'title' => 'Editor Card Title',
                                    'props' => [
                                        'preset' => 'feature_card_preset',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $visitor->visit($sectionBlock);

        $this->assertIsArray($result['rows']);
        /** @var list<array{columns: list<array{blocks: list<array{title: string, text: string, props: array<string, mixed>}>}>}> $rows */
        $rows = $result['rows'];
        $hydratedCard = $rows[0]['columns'][0]['blocks'][0];
        $this->assertSame('Editor Card Title', $hydratedCard['title']);
        $this->assertSame('<p>Default Preset Card Body</p>', $hydratedCard['text']);
        $this->assertSame('elevated', $hydratedCard['props']['variant']);
        $this->assertSame('lg', $hydratedCard['props']['padding']);
    }
}
