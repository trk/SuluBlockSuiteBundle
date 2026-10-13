<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline;
use Totoglu\SuluBlockSuiteBundle\Transformer\DefaultAttributesTransformer;
use Totoglu\SuluBlockSuiteBundle\Transformer\DynamicContentTransformer;
use Totoglu\SuluBlockSuiteBundle\Twig\DynamicContentTwigExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class BlockTransformIntegrationTest extends TestCase
{
    public function testBlockTransformFilterInTwigTemplate(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->willReturn([
                'title' => 'Dynamic Resolved Title',
                'url' => '/dynamic-url',
            ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);
        $pipeline = new BlockTransformPipeline();
        $pipeline->addTransformer(new DynamicContentTransformer($hydrator));
        $pipeline->addTransformer(new DefaultAttributesTransformer());

        $extension = new DynamicContentTwigExtension($pipeline);

        $loader = new ArrayLoader([
            'test_block.html.twig' => <<< 'TWIG'
{% set block = block|block_transform(_context) %}
{% if block %}
<h1>{{ block.title }}</h1>
<a href="{{ block.link }}">Link</a>
{% endif %}
TWIG
        ]);

        $twig = new Environment($loader);
        $twig->addExtension($extension);

        $inputBlock = [
            'type' => 'headline',
            'title' => 'Initial Title',
            'link' => '/initial',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'current_page',
                    'mapping' => [
                        'title' => 'title',
                        'link' => 'url',
                    ],
                ],
            ],
        ];

        $rendered = $twig->render('test_block.html.twig', [
            'block' => $inputBlock,
            'page' => ['title' => 'Context Page Title'],
        ]);

        $this->assertStringContainsString('<h1>Dynamic Resolved Title</h1>', $rendered);
        $this->assertStringContainsString('<a href="/dynamic-url">Link</a>', $rendered);
    }

    public function testBlockTransformSkipsRenderingWhenConditionFails(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->willReturn([
                'title' => 'Resolved Title',
                'status' => 'draft',
            ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);
        $pipeline = new BlockTransformPipeline();
        $pipeline->addTransformer(new DynamicContentTransformer($hydrator));
        $pipeline->addTransformer(new DefaultAttributesTransformer());

        $extension = new DynamicContentTwigExtension($pipeline);

        $loader = new ArrayLoader([
            'test_conditional.html.twig' => <<< 'TWIG'
{% set block = block|block_transform(_context) %}
{% if block %}
<h1>{{ block.title }}</h1>
{% else %}
<!-- OMITTED -->
{% endif %}
TWIG
        ]);

        $twig = new Environment($loader);
        $twig->addExtension($extension);

        $inputBlock = [
            'type' => 'headline',
            'title' => 'Initial Title',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'current_page',
                    'mapping' => [
                        'title' => 'title',
                    ],
                    'condition' => [
                        'field' => 'status',
                        'operator' => 'equals',
                        'value' => 'published',
                    ],
                ],
            ],
        ];

        $rendered = $twig->render('test_conditional.html.twig', [
            'block' => $inputBlock,
        ]);

        $this->assertStringContainsString('<!-- OMITTED -->', $rendered);
        $this->assertStringNotContainsString('<h1>', $rendered);
    }
}
