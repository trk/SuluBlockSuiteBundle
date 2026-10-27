<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Layout\FlatFlowLayoutGrouper;

final class FlatFlowLayoutGrouperTest extends TestCase
{
    public function testGroupsFlatLeafBlocksAndBreaksIntoSectionRowColumnHierarchy(): void
    {
        $blocks = [
            ['type' => 'navbar', 'brand_title' => 'Brand'],
            ['type' => 'section_break', 'props' => ['bg_style' => 'muted', 'padding' => 'lg']],
            ['type' => 'text', 'content' => '<p>Left</p>'],
            ['type' => 'column_break', 'props' => ['col_span' => '6']],
            ['type' => 'text', 'content' => '<p>Right</p>'],
        ];

        $entries = FlatFlowLayoutGrouper::buildTopLevelEntries($blocks, ['navbar']);

        self::assertCount(2, $entries);
        self::assertFalse($entries[0]['is_auto_section']);
        self::assertSame('navbar', $entries[0]['block']['type'] ?? null);

        self::assertTrue($entries[1]['is_auto_section']);
        self::assertSame('muted', $entries[1]['props']['bg_style'] ?? null);
        self::assertSame('lg', $entries[1]['props']['padding'] ?? null);
        self::assertCount(1, $entries[1]['rows'] ?? []);
        self::assertSame('halves', $entries[1]['rows'][0]['props']['layout'] ?? null);
        self::assertCount(2, $entries[1]['rows'][0]['columns']);
        self::assertSame('6', $entries[1]['rows'][0]['columns'][1]['props']['col_span'] ?? null);
    }

    public function testExtractsRowColumnsFromExplicitRowWithColumnBreaks(): void
    {
        $row = [
            'type' => 'row',
            'blocks' => [
                ['type' => 'text', 'title' => 'Col 1'],
                ['type' => 'column_break', 'props/col_span' => '4'],
                ['type' => 'text', 'title' => 'Col 2'],
            ],
        ];

        $columns = FlatFlowLayoutGrouper::extractRowColumns($row);

        self::assertCount(2, $columns);
        self::assertSame('4', $columns[0]['props']['col_span'] ?? null);
        self::assertSame('Col 1', $columns[0]['blocks'][0]['title'] ?? null);
        self::assertSame('Col 2', $columns[1]['blocks'][0]['title'] ?? null);
    }
}
