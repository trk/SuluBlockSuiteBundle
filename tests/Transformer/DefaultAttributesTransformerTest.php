<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Transformer\DefaultAttributesTransformer;

final class DefaultAttributesTransformerTest extends TestCase
{
    public function testDefaultAttributesExtraction(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'props' => [
                'name' => 'Hero Card',
                'id' => 'custom-card-id',
                'class' => 'shadow-lg p-6',
                'attributes' => "data-aos=fade-up\naria-label=\"Special Card\"\nrequired",
                'css' => ".el-element { border-radius: 12px; }\n.el-element:hover { opacity: 0.8; }",
            ],
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('Hero Card', $transformed['_name']);
        $this->assertSame('custom-card-id', $transformed['_id']);
        $this->assertSame(['shadow-lg', 'p-6'], $transformed['_classes']);
        $this->assertSame('shadow-lg p-6', $transformed['_class']);

        /** @var array<string, mixed> $attrs */
        $attrs = $transformed['_attributes'];
        $this->assertSame('custom-card-id', $attrs['id']);
        $this->assertSame('fade-up', $attrs['data-aos']);
        $this->assertSame('Special Card', $attrs['aria-label']);
        $this->assertTrue($attrs['required']);

        /** @var string $attrStr */
        $attrStr = $transformed['_attributes_string'];
        $this->assertStringContainsString('id="custom-card-id"', $attrStr);
        $this->assertStringContainsString('data-aos="fade-up"', $attrStr);
        $this->assertStringContainsString('aria-label="Special Card"', $attrStr);
        $this->assertStringContainsString('required', $attrStr);

        /** @var string $scopedCss */
        $scopedCss = $transformed['_scoped_css'];
        $this->assertStringContainsString('#custom-card-id { border-radius: 12px; }', $scopedCss);
        $this->assertStringContainsString('#custom-card-id:hover { opacity: 0.8; }', $scopedCss);
        $this->assertStringNotContainsString('.el-element', $scopedCss);
    }

    public function testDisabledStatusMarksHidden(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'button',
            'props' => [
                'status' => 'disabled',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertTrue($transformed['_hidden']);
    }

    public function testGeneratesIdWhenCssPresentAndIdOmitted(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'headline',
            'props' => [
                'css' => '.el-element { color: red; }',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        /** @var string $genId */
        $genId = $transformed['_id'];
        $this->assertStringStartsWith('el-', $genId);

        /** @var string $scopedCss */
        $scopedCss = $transformed['_scoped_css'];
        $this->assertStringContainsString('#' . $genId . ' { color: red; }', $scopedCss);
    }

    public function testScopeCssWithoutElElementPrefixesSelectors(): void
    {
        $css = "h2 { font-size: 2rem; }\np { margin: 0; }";
        $scoped = DefaultAttributesTransformer::scopeCss($css, '#my-block');

        $this->assertStringContainsString('#my-block h2 { font-size: 2rem; }', $scoped);
        $this->assertStringContainsString('#my-block p { margin: 0; }', $scoped);
    }
}
