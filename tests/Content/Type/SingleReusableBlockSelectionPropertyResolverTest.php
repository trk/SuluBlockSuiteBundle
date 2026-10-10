<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Content\Type;

use PHPUnit\Framework\TestCase;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Totoglu\SuluBlockSuiteBundle\Content\Type\SingleReusableBlockSelectionPropertyResolver;

final class SingleReusableBlockSelectionPropertyResolverTest extends TestCase
{
    private SingleReusableBlockSelectionPropertyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SingleReusableBlockSelectionPropertyResolver();
    }

    public function testGetType(): void
    {
        self::assertSame('single_reusable_block_selection', SingleReusableBlockSelectionPropertyResolver::getType());
    }

    public function testResolveWithValidId(): void
    {
        $id = 'snippet-uuid-1234';
        $result = $this->resolver->resolve($id, 'en');

        self::assertSame($id, $result->getView()['id'] ?? null);
        $references = \iterator_to_array($result->getAllReferencesRecursively());
        self::assertCount(1, $references);
        self::assertSame(SnippetInterface::RESOURCE_KEY, $references[0]->getResourceKey());
        self::assertSame($id, $references[0]->getResourceId());
    }

    public function testResolveWithNullOrEmpty(): void
    {
        $resultNull = $this->resolver->resolve(null, 'en');
        self::assertNull($resultNull->getContent());
        self::assertNull($resultNull->getView()['id'] ?? null);

        $resultEmpty = $this->resolver->resolve('', 'en');
        self::assertNull($resultEmpty->getContent());
        self::assertNull($resultEmpty->getView()['id'] ?? null);
    }
}
