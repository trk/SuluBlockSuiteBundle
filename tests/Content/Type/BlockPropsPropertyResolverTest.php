<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Content\Type;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Content\Type\BlockPropsPropertyResolver;

final class BlockPropsPropertyResolverTest extends TestCase
{
    private BlockPropsPropertyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new BlockPropsPropertyResolver();
    }

    public function testGetType(): void
    {
        self::assertSame('block_props', BlockPropsPropertyResolver::getType());
    }

    public function testResolveWithArray(): void
    {
        $data = ['margin_top' => 'mt-4', 'custom_class' => 'test-class'];
        $result = $this->resolver->resolve($data, 'en');

        self::assertSame($data, $result->getContent());
        self::assertSame(['value' => $data], $result->getView());
    }

    public function testResolveWithNonArray(): void
    {
        $result = $this->resolver->resolve('invalid-string', 'en');

        self::assertSame([], $result->getContent());
        self::assertSame(['value' => []], $result->getView());
    }
}
