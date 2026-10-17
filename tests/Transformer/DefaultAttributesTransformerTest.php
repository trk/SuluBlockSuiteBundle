<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Security\CssScoper;
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

    public function testRejectsMalformedElementIdAndStripsCssObfuscation(): void
    {
        $transformer = new DefaultAttributesTransformer();
        $transformed = $transformer->transform([
            'type' => 'card',
            'props' => [
                'id' => 'bad id, body { display:none }',
                'css' => '.el-element { background: u/**/rl("javascript:alert(1)"); content: \65 xpression(alert(1)); }',
            ],
        ], []);

        $this->assertIsString($transformed['_id']);
        $this->assertStringStartsWith('el-', $transformed['_id']);
        $this->assertIsString($transformed['_scoped_css']);
        $this->assertStringNotContainsString('/*', $transformed['_scoped_css']);
        $this->assertStringNotContainsString('\\', $transformed['_scoped_css']);
        $this->assertStringNotContainsString('javascript:', $transformed['_scoped_css']);
    }

    public function testGeneratedIdIsStableAndScopesCss(): void
    {
        $transformer = new DefaultAttributesTransformer();
        $block = [
            'type' => 'headline',
            'props' => ['css' => 'color: red;'],
        ];

        $first = $transformer->transform($block, []);
        $second = $transformer->transform($block, []);

        $this->assertIsString($first['_id']);
        $this->assertMatchesRegularExpression('/^' . CssScoper::GENERATED_ID_PREFIX . '[0-9a-f]{8}$/', $first['_id']);
        $this->assertSame($first['_id'], $second['_id']);
        $this->assertSame('#' . $first['_id'] . ' { color: red; }', $first['_scoped_css']);
    }

    public function testDerivesMediaAndLinkFieldsGenerically(): void
    {
        $transformer = new DefaultAttributesTransformer(null, ['col-sm' => 400]);

        $block = [
            'type' => 'custom_banner',
            'hero_banner' => [
                'url' => 'https://example.com/banner.jpg',
                'formats' => ['col-sm' => 'https://example.com/banner-400.webp'],
                'focusPoint' => ['x' => 25, 'y' => 75],
            ],
            'author_avatar' => [
                'url' => 'https://example.com/avatar.png',
                'formats' => ['sulu-200x200' => 'https://example.com/avatar-200.webp'],
            ],
            'primary_cta' => [
                'url' => 'https://example.com/signup',
                'target' => '_blank',
            ],
            'link' => [
                'href' => '/contact',
                'provider' => 'page',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('https://example.com/banner.jpg', $transformed['hero_banner_url']);
        $this->assertSame('https://example.com/banner-400.webp 400w', $transformed['hero_banner_srcset']);
        $this->assertSame('object-position: 25% 75%;', $transformed['hero_banner_focus_style']);

        $this->assertSame('https://example.com/avatar.png', $transformed['author_avatar_url']);
        $this->assertSame('https://example.com/avatar-200.webp 200w', $transformed['author_avatar_srcset']);
        $this->assertNull($transformed['author_avatar_focus_style']);

        $this->assertSame('https://example.com/signup', $transformed['primary_cta_url']);
        $this->assertArrayNotHasKey('primary_cta_srcset', $transformed);

        $this->assertSame('/contact', $transformed['link_url']);
        $this->assertArrayNotHasKey('link_srcset', $transformed);

        SuluDataNormalizer::setDefaultSrcSetFormats([]);
    }
}
