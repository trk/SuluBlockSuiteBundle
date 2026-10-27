<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockXmlSchemaExtractor;

final class BlockXmlSchemaExtractorTest extends TestCase
{
    public function testExtractSchemaFromCoreReusableBlockXmlAndCoreSharedSchemas(): void
    {
        $blocksDir = \dirname(__DIR__, 2) . '/config/templates/blocks';
        $extractor = new BlockXmlSchemaExtractor($blocksDir);

        self::assertTrue($extractor->hasBlockDocument('reusable'));
        self::assertFalse($extractor->hasBlockDocument('non_existent'));

        $summaries = $extractor->listBlockSummaries('en');
        self::assertCount(1, $summaries);
        self::assertSame('reusable', $summaries[0]['type']);

        $schema = $extractor->extractSchema('reusable', 'en');
        self::assertNotNull($schema);
        self::assertSame('reusable', $schema['resolved_type']);
        self::assertNotEmpty($schema['content_fields']);

        $dynamicSchema = BlockXmlSchemaExtractor::getCoreDynamicPropsSchema();
        $dynamicNames = \array_column($dynamicSchema, 'name');
        self::assertContains('dynamic_source', $dynamicNames);
        self::assertContains('dynamic_sort_order', $dynamicNames);

        $advancedSchema = BlockXmlSchemaExtractor::getCoreAdvancedPropsSchema();
        $advancedNames = \array_column($advancedSchema, 'name');
        self::assertSame(['name', 'id', 'class', 'attributes', 'css'], $advancedNames);
    }
}
