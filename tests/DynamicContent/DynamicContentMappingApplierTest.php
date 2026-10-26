<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentMappingApplier;

final class DynamicContentMappingApplierTest extends TestCase
{
    private DynamicContentMappingApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new DynamicContentMappingApplier();
    }

    public function testExtractValueSupportsDotNotationFallbacksAndModifiers(): void
    {
        $data = [
            'headline' => '',
            'title' => 'Fallback Title',
            'summary' => '',
            'content' => '<p>Hello <strong>world</strong>! This is a rich text paragraph.</p>',
            'nested' => [
                'author' => [
                    'name' => 'Ada Lovelace',
                ],
            ],
        ];

        self::assertSame('Fallback Title', $this->applier->extractValue($data, 'headline|title'));
        self::assertSame('Ada Lovelace', $this->applier->extractValue($data, 'nested.author.name'));
        self::assertSame(
            'Hello world! This is a rich text paragraph.',
            $this->applier->extractValue($data, 'summary|content:plain'),
        );
        self::assertSame(
            'Hello world!...',
            $this->applier->extractValue($data, 'summary|content:length=15'),
        );
    }

    public function testApplyMappingAppliesExplicitMappingsInlineTokensAndSubBlockNormalization(): void
    {
        $block = [
            'type' => 'card',
            'title' => 'Static Title',
            'subtitle' => '{{ category_title }} • {{ date }} • {{ missing_token }}',
            'badges' => [],
            'buttons' => [],
        ];

        $pageData = [
            'title' => 'Dynamic Title',
            'category_title' => 'Engineering',
            'date' => '2026-10-26',
            'tags' => ['PHP 8.5', 'Sulu 3'],
            'cta_urls' => ['/read-more'],
        ];

        $appliedCount = 0;
        $hydrated = $this->applier->applyMapping(
            block: $block,
            pageData: $pageData,
            mapping: [
                'title' => 'title',
                'badges' => 'tags',
                'buttons' => 'cta_urls',
            ],
            appliedCount: $appliedCount,
        );

        self::assertSame('Dynamic Title', $hydrated['title']);
        self::assertSame('Engineering • 2026-10-26', $hydrated['subtitle']);
        self::assertSame([
            ['type' => 'badge', 'title' => 'PHP 8.5'],
            ['type' => 'badge', 'title' => 'Sulu 3'],
        ], $hydrated['badges']);
        self::assertSame([
            ['type' => 'button', 'url' => '/read-more'],
        ], $hydrated['buttons']);
        self::assertGreaterThanOrEqual(4, $appliedCount);
    }

    public function testApplyMappingAutoMapsEqualFieldNamesWhenMappingIsEmptyAndNoInlineTokens(): void
    {
        $block = [
            'type' => 'hero',
            'title' => 'Default Hero',
            'description' => 'Default Description',
        ];

        $pageData = [
            'title' => 'Auto Mapped Hero',
            'description' => 'Auto Mapped Description',
        ];

        $appliedCount = 0;
        $hydrated = $this->applier->applyMapping($block, $pageData, [], $appliedCount);

        self::assertSame('Auto Mapped Hero', $hydrated['title']);
        self::assertSame('Auto Mapped Description', $hydrated['description']);
        self::assertSame(2, $appliedCount);
    }

    public function testEvaluateConditionSupportsOperators(): void
    {
        $data = [
            'title' => 'Sulu Block Suite',
            'views' => 42,
            'tags' => ['cms', 'symfony'],
            'empty_field' => '   ',
        ];

        self::assertTrue($this->applier->evaluateCondition($data, ['field' => 'title', 'operator' => 'not_empty']));
        self::assertTrue($this->applier->evaluateCondition($data, ['field' => 'empty_field', 'operator' => 'is_empty']));
        self::assertTrue($this->applier->evaluateCondition($data, ['field' => 'title', 'operator' => 'contains', 'value' => 'block']));
        self::assertTrue($this->applier->evaluateCondition($data, ['field' => 'tags', 'operator' => 'contains', 'value' => 'Symfony']));
        self::assertTrue($this->applier->evaluateCondition($data, ['field' => 'views', 'operator' => 'gte', 'value' => '40']));
        self::assertFalse($this->applier->evaluateCondition($data, ['field' => 'views', 'operator' => 'lt', 'value' => '10']));
    }
}
