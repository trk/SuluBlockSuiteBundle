<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Util\PropReader;

final class PropReaderTest extends TestCase
{
    public function testToStringCoercesScalarsAndStringables(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from-object';
            }
        };

        $this->assertSame('text', PropReader::toString('text'));
        $this->assertSame('42', PropReader::toString(42));
        $this->assertSame('1.5', PropReader::toString(1.5));
        $this->assertSame('from-object', PropReader::toString($stringable));
        $this->assertNull(PropReader::toString(null));
        $this->assertNull(PropReader::toString(true));
        $this->assertNull(PropReader::toString(['a']));
        $this->assertNull(PropReader::toString(new \stdClass()));
    }

    /**
     * @return iterable<string, array{mixed, bool, bool}>
     */
    public static function booleanProvider(): iterable
    {
        yield 'true' => [true, false, true];
        yield 'false' => [false, true, false];
        yield 'int one' => [1, false, true];
        yield 'int zero' => [0, true, false];
        yield 'string true' => ['TRUE', false, true];
        yield 'string yes' => [' yes ', false, true];
        yield 'string on' => ['on', false, true];
        yield 'string 1' => ['1', false, true];
        yield 'string false' => ['false', true, false];
        yield 'string no' => ['no', true, false];
        yield 'string off' => ['off', true, false];
        yield 'string none' => ['none', true, false];
        yield 'string 0' => ['0', true, false];
        yield 'empty string' => ['', true, false];
        yield 'other non-empty string' => ['anything', false, true];
        yield 'null uses default true' => [null, true, true];
        yield 'null uses default false' => [null, false, false];
        yield 'float uses default' => [1.0, false, false];
        yield 'array uses default' => [[], true, true];
    }

    #[DataProvider('booleanProvider')]
    public function testToBool(mixed $value, bool $default, bool $expected): void
    {
        $this->assertSame($expected, PropReader::toBool($value, $default));
    }

    public function testNullableStringTrimsAndFallsBack(): void
    {
        $values = [
            'empty' => '   ',
            'inherit' => 'inherit',
            'value' => '  hello ',
            'number' => 7,
            'list' => ['x'],
        ];

        $this->assertSame('hello', PropReader::nullableString($values, 'value'));
        $this->assertSame('7', PropReader::nullableString($values, 'number'));
        $this->assertNull(PropReader::nullableString($values, 'missing'));
        $this->assertNull(PropReader::nullableString($values, 'empty'));
        $this->assertNull(PropReader::nullableString($values, 'list'));
        $this->assertSame('hello', PropReader::nullableString($values, 'empty', 'value'));
        $this->assertSame('hello', PropReader::nullableString($values, 'missing', 'value'));
        $this->assertSame('inherit', PropReader::nullableString($values, 'inherit'));
        $this->assertNull(PropReader::nullableString($values, 'inherit', null, true));
        $this->assertSame('hello', PropReader::nullableString($values, 'inherit', 'value', true));
    }

    public function testStringReturnsDefaultWhenUnresolved(): void
    {
        $values = ['a' => ' x ', 'b' => ''];

        $this->assertSame('x', PropReader::string($values, 'a', 'fallback'));
        $this->assertSame('fallback', PropReader::string($values, 'b', 'fallback'));
        $this->assertSame('x', PropReader::string($values, 'b', 'fallback', 'a'));
        $this->assertSame('', PropReader::string([], 'missing'));
        $this->assertSame('fallback', PropReader::string(['a' => 'inherit'], 'a', 'fallback', null, true));
    }

    public function testBoolReturnsDefaultForMissingOrNull(): void
    {
        $values = ['on' => true, 'off' => 'false', 'null' => null, 'empty' => ''];

        $this->assertTrue(PropReader::bool($values, 'on'));
        $this->assertFalse(PropReader::bool($values, 'off', true));
        $this->assertTrue(PropReader::bool($values, 'null', true));
        $this->assertTrue(PropReader::bool($values, 'missing', true));
        $this->assertFalse(PropReader::bool($values, 'missing'));
        $this->assertFalse(PropReader::bool($values, 'empty', true));
    }
}
