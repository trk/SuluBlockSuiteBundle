<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Preset\PresetPayloadNormalizer;

final class PresetPayloadNormalizerTest extends TestCase
{
    public function testNormalizesCardShorthandBadgeAndLinkIntoElementsAndIconMediaType(): void
    {
        [$props, $values] = PresetPayloadNormalizer::normalizeBlockPropsAndValues(
            'card',
            [],
            [
                'title' => 'Fast Delivery',
                'icon' => 'lucide-zap',
                'badge' => 'New',
                'link_text' => 'Read more',
                'link' => '/fast-delivery',
                'link_style' => 'primary',
            ],
        );

        self::assertSame('primary', $props['button_style'] ?? null);
        self::assertSame('icon', $values['media_type'] ?? null);
        self::assertArrayNotHasKey('badge', $values);
        self::assertArrayNotHasKey('link_text', $values);
        /** @var list<array<string, mixed>> $elements */
        $elements = $values['elements'] ?? [];
        self::assertCount(2, $elements);
        self::assertSame('badge', $elements[0]['type'] ?? null);
        self::assertSame('New', $elements[0]['title'] ?? null);
        self::assertSame('button', $elements[1]['type'] ?? null);
        self::assertSame('Read more', $elements[1]['title'] ?? null);
    }

    public function testNormalizesSectionSingleColumnRowLayoutAndColSpan(): void
    {
        [, $values] = PresetPayloadNormalizer::normalizeBlockPropsAndValues(
            'section',
            [],
            [
                'rows' => [
                    [
                        'type' => 'row',
                        'gap' => 'lg',
                        'columns' => [
                            [
                                'align' => 'center',
                                'blocks' => [],
                            ],
                        ],
                    ],
                ],
            ],
        );

        /** @var list<array{props: array<string, mixed>, columns: list<array{props: array<string, mixed>}>}> $rows */
        $rows = $values['rows'] ?? [];
        self::assertSame('lg', $rows[0]['props']['gap'] ?? null);
        self::assertSame('12-col', $rows[0]['props']['layout'] ?? null);
        self::assertSame('12', $rows[0]['columns'][0]['props']['col_span'] ?? null);
        self::assertSame('center', $rows[0]['columns'][0]['props']['align'] ?? null);
    }

    public function testAutoExtractsChildPresetsFromContainerSectionPreset(): void
    {
        $sectionPreset = [
            'id' => 'hero_split',
            'title' => 'Hero Split',
            'description' => 'Split layout',
            'preview_image' => '/img/hero.png',
            'props' => [],
            'values' => [
                'rows' => [
                    [
                        'type' => 'row',
                        'columns' => [
                            [
                                'blocks' => [
                                    [
                                        'type' => 'card',
                                        'props' => ['variant' => 'elevated'],
                                        'title' => 'Card One',
                                    ],
                                    [
                                        'type' => 'card',
                                        'props' => ['variant' => 'outlined'],
                                        'title' => 'Card Two',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $extracted = PresetPayloadNormalizer::appendAutoExtractedChildPresets(
            'card',
            'en',
            [],
            static fn (string $type): array => 'section' === $type ? ['hero_split' => $sectionPreset] : [],
        );

        self::assertArrayHasKey('hero_split_card_1', $extracted);
        self::assertArrayHasKey('hero_split_card_2', $extracted);
        self::assertSame('Hero Split #1', $extracted['hero_split_card_1']['title']);
        self::assertSame('elevated', $extracted['hero_split_card_1']['props']['variant'] ?? null);
        self::assertSame('Card Two', $extracted['hero_split_card_2']['values']['title'] ?? null);
    }
}
