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

    public function testSanitizesLinkFieldWhenValueIsDangerousString(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'link' => 'javascript:alert(1)',
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('#', $transformed['link']);
        $this->assertSame('#', $transformed['link_url']);
    }

    public function testSanitizesLinkFieldWhenValueIsArrayWithDangerousUrlOrHref(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'link' => [
                'url' => 'javascript:alert(1)',
                'target' => '_blank',
            ],
            'cta_link' => [
                'href' => 'data:text/html,evil',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertIsArray($transformed['link']);
        $this->assertSame('#', $transformed['link']['url']);
        $this->assertSame('#', $transformed['link_url']);

        $this->assertIsArray($transformed['cta_link']);
        $this->assertSame('#', $transformed['cta_link']['href']);
        $this->assertSame('#', $transformed['cta_link_url']);
    }

    public function testAutoDetectsRawStringUrlAsLinkAndSanitizes(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'button',
            'button_link' => 'https://example.com/demo',
            'evil_link' => 'javascript:alert(1)',
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('https://example.com/demo', $transformed['button_link']);
        $this->assertSame('https://example.com/demo', $transformed['button_link_url']);

        $this->assertSame('#', $transformed['evil_link']);
        $this->assertSame('#', $transformed['evil_link_url']);
    }

    public function testSanitizesFieldsEndingWithUrl(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'website_url' => 'javascript:alert(1)',
            'button_url' => 'javascript:/*\x80*/alert(1)',
            'external_url' => 'jav&#x0D;ascript:alert(1)',
            'safe_url' => 'https://example.com',
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('#', $transformed['website_url']);
        $this->assertSame('#', $transformed['website_url_url']);

        $this->assertSame('#', $transformed['button_url']);
        $this->assertSame('#', $transformed['button_url_url']);

        $this->assertSame('#', $transformed['external_url']);
        $this->assertSame('#', $transformed['external_url_url']);

        $this->assertSame('https://example.com', $transformed['safe_url']);
        $this->assertSame('https://example.com', $transformed['safe_url_url']);
    }

    public function testSanitizesObfuscatedUrlSchemesInArbitraryFields(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'custom_action' => "java\tscript:alert(1)",
            'encoded_action' => '&#x6A;avascript:alert(1)',
            'tab_link_target' => "data\t:text/html,<script>alert(1)</script>",
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('#', $transformed['custom_action']);
        $this->assertSame('#', $transformed['custom_action_url']);

        $this->assertSame('#', $transformed['encoded_action']);
        $this->assertSame('#', $transformed['encoded_action_url']);

        $this->assertSame('#', $transformed['tab_link_target']);
        $this->assertSame('#', $transformed['tab_link_target_url']);
    }

    public function testDetectsMediaDisguisedDangerousUrlsAsLinks(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'website_url' => 'javascript:alert(1)//pic.jpg',
            'button_url' => 'javascript:alert(1)#pic.png',
            'avatar_img' => 'data:image/svg+xml,<svg onload=alert(1)>#img.svg',
            'custom_link' => 'jav&#97script:alert(1)//banner.webp',
            'colon_entity_link' => 'javascript&#58alert(1)//photo.jpg',
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('#', $transformed['website_url']);
        $this->assertSame('#', $transformed['website_url_url']);

        $this->assertSame('#', $transformed['button_url']);
        $this->assertSame('#', $transformed['button_url_url']);

        $this->assertSame('#', $transformed['avatar_img']);
        $this->assertSame('#', $transformed['avatar_img_url']);

        $this->assertSame('#', $transformed['custom_link']);
        $this->assertSame('#', $transformed['custom_link_url']);

        $this->assertSame('#', $transformed['colon_entity_link']);
        $this->assertSame('#', $transformed['colon_entity_link_url']);
    }

    public function testSynchronizesSanitizedUrlsBackIntoPropsAndUnderscoreProps(): void
    {
        $transformer = new DefaultAttributesTransformer();

        $block = [
            'type' => 'card',
            'props' => [
                'website_url' => 'javascript:alert(1)',
                'cta_link' => 'jav&#97script:alert(2)',
                'media_trick' => 'javascript:alert(3)//pic.jpg',
            ],
        ];

        $transformed = $transformer->transform($block, []);

        $this->assertSame('#', $transformed['website_url']);
        $this->assertIsArray($transformed['props']);
        $this->assertIsArray($transformed['_props']);
        /** @var array<string, mixed> $props */
        $props = $transformed['props'];
        /** @var array<string, mixed> $underscoreProps */
        $underscoreProps = $transformed['_props'];

        $this->assertSame('#', $props['website_url']);
        $this->assertSame('#', $underscoreProps['website_url']);

        $this->assertSame('#', $transformed['cta_link']);
        $this->assertSame('#', $props['cta_link']);
        $this->assertSame('#', $underscoreProps['cta_link']);

        $this->assertSame('#', $transformed['media_trick']);
        $this->assertSame('#', $props['media_trick']);
        $this->assertSame('#', $underscoreProps['media_trick']);

        // Verify AbstractBlockComponent ingestion
        $comp = new class extends \Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent {};
        $comp->initializeBlockComponent(['block' => $transformed]);
        $this->assertSame('#', $comp->get('website_url'));
        $this->assertSame('#', $comp->get('cta_link'));
        $this->assertSame('#', $comp->get('media_trick'));
    }
}
