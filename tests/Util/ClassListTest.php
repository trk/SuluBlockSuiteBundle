<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Util\ClassList;

final class ClassListTest extends TestCase
{
    public function testJoinNormalizesWhitespaceAndSkipsFalsyParts(): void
    {
        $this->assertSame('', ClassList::join());
        $this->assertSame('', ClassList::join(null, false, '', '   '));
        $this->assertSame(
            'flex items-center gap-2 text-primary',
            ClassList::join('flex', ['items-center', '', null, 'gap-2'], false, "  text-primary \n "),
        );
        $this->assertSame('a b c', ClassList::join("a\t\tb", [['c', [false]]]));
    }

    public function testJoinIgnoresNonStringNestedValues(): void
    {
        $this->assertSame('a', ClassList::join(['a', 1, 2.5, true, new \stdClass()]));
    }

    public function testJoinKeepsDuplicates(): void
    {
        $this->assertSame('a a', ClassList::join('a', 'a'));
    }

    public function testTokensSplitsTrimsAndDeduplicatesPreservingOrder(): void
    {
        $this->assertSame([], ClassList::tokens(null));
        $this->assertSame([], ClassList::tokens(''));
        $this->assertSame([], ClassList::tokens("  \n\t "));
        $this->assertSame(['shadow-lg', 'p-6'], ClassList::tokens('  shadow-lg   p-6 shadow-lg '));
        $this->assertSame(['a', 'b', 'c'], ClassList::tokens("a\tb\nc a"));
    }
}
