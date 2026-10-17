<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Twig\Component;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent;

final class AbstractBlockComponentTest extends TestCase
{
    public function testDefaultsAndPropAccessors(): void
    {
        $component = new DummyHeadlineComponent();
        $component->load(
            data: ['title' => 'Hello Block Suite'],
            props: ['text_align' => 'center', 'columns_md' => '3', 'full_width' => 'true', 'opacity' => '0.75'],
        );

        self::assertSame('Hello Block Suite', $component->get('title'));
        self::assertSame('Hello Block Suite', $component->string('title'));
        self::assertSame('h2', $component->stringProp('tag'));
        self::assertSame('lg', $component->stringProp('size'));
        self::assertSame('center', $component->prop('text_align', 'left'));
        self::assertSame(3, $component->intProp('columns_md', 1));
        self::assertTrue($component->boolProp('full_width', false));
        self::assertSame(0.75, $component->floatProp('opacity', 1.0));
        self::assertSame('h2', $component->enumProp('tag', ['h1', 'h2', 'h3'], 'h2'));

        $component->setProp('tag', 'invalid-tag');
        self::assertSame('h2', $component->enumProp('tag', ['h1', 'h2', 'h3'], 'h2'));
    }

    public function testExplicitFalsyBoolPropOverridesTrueDefault(): void
    {
        $component = new DummyVideoComponent();
        $component->load(data: ['video' => 'https://example.com/v.mp4']);
        self::assertTrue($component->boolProp('controls', true));

        $component->load(
            data: ['video' => 'https://example.com/v.mp4'],
            props: ['controls' => false],
        );
        self::assertFalse($component->boolProp('controls', true));

        $component->load(
            data: ['video' => 'https://example.com/v.mp4'],
            props: ['controls' => 'false'],
        );
        self::assertFalse($component->boolProp('controls', true));
    }

    public function testCamelCaseToSnakeCaseNormalizationAndBlockPayload(): void
    {
        $component = new DummyHeadlineComponent();
        $leftoverAttributes = $component->initializeBlockComponent([
            'block' => [
                'type' => 'headline',
                'title' => 'From Sulu Block',
                'linkTitle' => 'Read More',
                'props' => [
                    'textAlign' => 'right',
                    'marginTop' => 'lg',
                ],
            ],
            'titleColor' => 'primary',
            'class' => 'custom-root-class',
            'data-testid' => 'headline-1',
            'aria-label' => 'Main heading',
        ]);

        self::assertSame('From Sulu Block', $component->string('title'));
        self::assertSame('Read More', $component->string('link_title'));
        self::assertSame('right', $component->stringProp('text_align'));
        self::assertSame('lg', $component->stringProp('margin_top'));
        self::assertSame('primary', $component->stringProp('title_color'));

        self::assertSame([
            'class' => 'custom-root-class',
            'data-testid' => 'headline-1',
            'aria-label' => 'Main heading',
        ], $leftoverAttributes);
    }

    public function testSingletonStateResetBetweenMounts(): void
    {
        $component = new DummyHeadlineComponent();

        $component->initializeBlockComponent([
            'title' => 'First Render',
            'tag' => 'h1',
            'text_align' => 'center',
        ]);
        self::assertSame('First Render', $component->string('title'));
        self::assertSame('h1', $component->stringProp('tag'));
        self::assertSame('center', $component->stringProp('text_align'));

        // Second mount on the same singleton instance without tag or text_align
        $component->initializeBlockComponent([
            'title' => 'Second Render',
        ]);
        self::assertSame('Second Render', $component->string('title'));
        self::assertSame('h2', $component->stringProp('tag'));
        self::assertSame('left', $component->stringProp('text_align'));
    }

    public function testExplicitPropsOverrideRootDefaultsInIngestBlockArray(): void
    {
        $component = new DummyHeadlineComponent();
        $component->initializeBlockComponent([
            'block' => [
                'type' => 'headline',
                'title' => 'Hero Heading',
                'tag' => 'h2',
                'size' => 'md',
                'props' => [
                    'tag' => 'h1',
                    'size' => 'xl',
                ],
            ],
        ]);

        self::assertSame('h1', $component->stringProp('tag'));
        self::assertSame('xl', $component->stringProp('size'));
    }

    public function testRichTextAndEmptinessDetection(): void
    {
        $component = new DummyTextComponent();

        $component->load(data: ['content' => '']);
        self::assertFalse($component->hasRichText('content'));
        self::assertFalse($component->shouldRender());

        $component->load(data: ['content' => '<p>   <br/> &nbsp; </p>']);
        self::assertFalse($component->hasRichText('content'));
        self::assertFalse($component->shouldRender());

        $component->load(data: ['content' => '<p><img src="/media/1/ inline.jpg" alt="" /></p>']);
        self::assertTrue($component->hasRichText('content'));
        self::assertTrue($component->shouldRender());

        $component->load(data: ['content' => '<p>Valid paragraph</p>']);
        self::assertTrue($component->hasRichText('content'));
        self::assertTrue($component->shouldRender());
    }

    public function testHasAnyAndHasAll(): void
    {
        $component = new DummyCardComponent();
        $component->load();
        self::assertFalse($component->shouldRender());
        self::assertTrue($component->is('empty'));

        $component->load(data: ['title' => 'Card Title']);
        self::assertTrue($component->hasAny('title', 'content', 'image'));
        self::assertFalse($component->hasAll('title', 'content'));
        self::assertTrue($component->shouldRender());
        self::assertFalse($component->is('empty'));

        $component->load(data: ['title' => 'Card Title', 'content' => '<p>Body</p>']);
        self::assertTrue($component->hasAll('title', 'content'));
    }

    public function testCapabilitiesAndIsQueries(): void
    {
        $grid = new DummyGridComponent();
        $grid->load(data: ['items' => [[], ['title' => '   ']]]);
        self::assertFalse($grid->is('multiple'));
        self::assertFalse($grid->shouldRender());

        $grid->load(
            data: [
                'items' => [
                    ['title' => 'Item 1'],
                    ['title' => 'Item 2'],
                ],
            ],
            props: [
                'masonry' => 'columns',
                'filter' => true,
            ],
        );

        self::assertTrue($grid->is('multiple'));
        self::assertTrue($grid->is('masonry'));
        self::assertTrue($grid->is('filter'));
        self::assertFalse($grid->is('container'));
        self::assertTrue($grid->shouldRender());

        $section = new DummySectionComponent();
        $section->load(data: ['rows' => [['columns' => [['blocks' => [['type' => 'headline', 'title' => 'Hi']]]]]]]);
        self::assertTrue($section->is('container'));
        self::assertTrue($section->shouldRender());
    }

    public function testAssignRoutesKnownDataKeysToDataAndOthersToProps(): void
    {
        $component = new DummyHeadlineComponent();
        $component->assign('title', 'Hello')->assign('textAlign', 'center');

        self::assertSame(['title' => 'Hello'], $component->data());
        self::assertSame('center', $component->props()['text_align']);
    }

    public function testMergePropsMergesNestedAndNamespacedProps(): void
    {
        $component = new DummyHeadlineComponent();
        $component->mergeProps(['props/size' => 'xl', 'props' => ['tag' => 'h1']]);

        self::assertSame('xl', $component->stringProp('size'));
        self::assertSame('h1', $component->stringProp('tag'));
        self::assertSame('left', $component->stringProp('text_align'));
    }

    public function testTransformerPropsCacheIsDecodedButNotLeakedAsProp(): void
    {
        $component = new DummyHeadlineComponent();
        $component->load(block: ['title' => 'Hi', '_props' => ['size' => 'sm']]);

        self::assertSame('sm', $component->stringProp('size'));
        self::assertArrayNotHasKey('_props', $component->props());
    }

    public function testHtmlAttributesAreNotWritableComponentProperties(): void
    {
        // Symfony UX ComponentFactory writes leftover mount data to any writable property.
        // HTML attributes returned from #[PreMount] must stay attributes.
        $accessor = PropertyAccess::createPropertyAccessorBuilder()->enableMagicSet()->getPropertyAccessor();
        $component = new DummyHeadlineComponent();

        self::assertFalse($accessor->isWritable($component, 'class'));
        self::assertFalse($accessor->isWritable($component, 'attributes'));
        self::assertSame(
            ['class' => 'w-full', 'data-foo' => 'bar'],
            $component->initializeBlockComponent(['title' => 'Hi', 'class' => 'w-full', 'data-foo' => 'bar']),
        );
    }

    public function testStringTransformsAndClassAndItemHelpers(): void
    {
        $component = new DummyHeadlineComponent();
        $component->load(
            data: [
                'title' => '  Hello World  ',
                'subtitle' => '   ',
            ],
            props: [
                'tag' => '  H2  ',
                'custom_id' => ' #MySection_1 ',
            ],
        );

        self::assertSame('Hello World', $component->string('title'));
        self::assertSame('  Hello World  ', $component->string('title', '', trim: false));
        self::assertSame('hello world', $component->string('title', '', fn: 'lower'));
        self::assertSame('HELLO WORLD', $component->string('title', '', fn: 'upper'));
        self::assertSame('hello-world', $component->string('title', '', fn: 'slug'));
        self::assertNull($component->nullableString('subtitle'));
        self::assertSame('h2', $component->stringProp('tag', 'h3', fn: 'lower'));
        self::assertSame('MySection_1', $component->ltrim($component->stringProp('custom_id'), '# '));
        self::assertSame('Hello', $component->rtrim('Hello///', '/'));
        self::assertSame('Twitter', $component->ucfirst('twitter'));
        self::assertSame('web-design-2026', $component->slug(' Web Design 2026 '));
        self::assertSame(['news', 'tech', 'ai'], $component->split(' news, , tech , ai '));
        self::assertSame('a / b', $component->join(['a', '', null, '  b  '], ' / '));
        self::assertSame(
            'flex items-center gap-2 text-primary',
            $component->classes('flex', ['items-center', '', null, 'gap-2'], false, '  text-primary  '),
        );
        self::assertTrue($component->oneOf('left', ['left', 'right']));
        self::assertFalse($component->oneOf('center', ['left', 'right']));
        self::assertSame('left', $component->matchEnum(' left ', ['left', 'right'], 'top'));
        self::assertSame('top', $component->matchEnum('inherit', ['left', 'right'], 'top'));
        self::assertSame(20, $component->clamp(25, 1, 20));
        self::assertSame(0.0, $component->clamp(-5.5, 0.0, 100.0));

        $item = [
            'title' => '  Item Title  ',
            'panel_style' => 'inherit',
            'link_style' => 'outline',
            'marker_type' => 'pin',
            'is_active' => 'true',
            'count' => '42',
            'x' => '75.5',
            'nested' => ['url' => 'https://example.com'],
        ];

        self::assertSame('Item Title', $component->itemString($item, 'title'));
        self::assertSame('  Item Title  ', $component->itemString($item, 'title', '', trim: false));
        self::assertSame('item title', $component->itemString($item, 'title', '', fn: 'lower'));
        self::assertNull($component->itemNullableString($item, 'missing'));
        self::assertSame('bordered', $component->itemInheritedString($item, 'panel_style', 'bordered'));
        self::assertSame('outline', $component->itemInheritedString($item, 'link_style', 'primary'));
        self::assertSame('pin', $component->itemEnum($item, 'marker_type', ['pulse', 'pin'], 'pulse'));
        self::assertSame('pulse', $component->itemEnum($item, 'panel_style', ['pulse', 'pin'], 'pulse'));
        self::assertTrue($component->itemBool($item, 'is_active'));
        self::assertSame(42, $component->itemInt($item, 'count'));
        self::assertSame(75.5, $component->itemFloat($item, 'x'));
        self::assertSame(['url' => 'https://example.com'], $component->itemArray($item, 'nested'));
    }
}

final class DummyHeadlineComponent extends AbstractBlockComponent
{
    protected function defaults(): array
    {
        return [
            'tag' => 'h2',
            'size' => 'lg',
            'text_align' => 'left',
        ];
    }

    public function shouldRender(): bool
    {
        return $this->has('title');
    }
}

final class DummyTextComponent extends AbstractBlockComponent
{
    public function shouldRender(): bool
    {
        return $this->hasRichText('content') || $this->has('text');
    }
}

final class DummyVideoComponent extends AbstractBlockComponent
{
    protected function defaults(): array
    {
        return [
            'controls' => true,
            'autoplay' => false,
        ];
    }

    public function shouldRender(): bool
    {
        return $this->has('video');
    }
}

final class DummyCardComponent extends AbstractBlockComponent
{
    public function shouldRender(): bool
    {
        return $this->hasAny('title', 'content', 'image', 'video', 'icon', 'buttons');
    }
}

final class DummyGridComponent extends AbstractBlockComponent
{
    protected function defaults(): array
    {
        return [
            'masonry' => 'none',
            'filter' => false,
        ];
    }

    protected function capabilities(): array
    {
        return [
            'masonry' => fn (): bool => 'columns' === $this->stringProp('masonry', 'none'),
        ];
    }

    public function shouldRender(): bool
    {
        return $this->is('multiple');
    }
}

final class DummySectionComponent extends AbstractBlockComponent
{
    public function shouldRender(): bool
    {
        return $this->is('container');
    }
}
