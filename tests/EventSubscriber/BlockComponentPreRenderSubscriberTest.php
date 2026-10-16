<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentMetadata;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Symfony\UX\TwigComponent\MountedComponent;
use Totoglu\SuluBlockSuiteBundle\EventSubscriber\BlockComponentPreRenderSubscriber;
use Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent;
use Twig\Runtime\EscaperRuntime;

final class BlockComponentPreRenderSubscriberTest extends TestCase
{
    public function testShortCircuitsTemplateWhenShouldRenderIsFalse(): void
    {
        $component = new class extends AbstractBlockComponent {
            public function shouldRender(): bool
            {
                return $this->has('title');
            }
        };
        $component->load(data: ['title' => '']);

        $mounted = new MountedComponent('Headline', $component, new ComponentAttributes([], new EscaperRuntime()));
        $metadata = new ComponentMetadata([
            'key' => 'Headline',
            'template' => '@SuluPrelineBlocks/components/Headline.html.twig',
        ]);
        $event = new PreRenderEvent($mounted, $metadata, ['existing' => 'var']);

        $subscriber = new BlockComponentPreRenderSubscriber();
        $subscriber->onPreRender($event);

        self::assertSame('@SuluBlockSuite/components/_empty.html.twig', $event->getTemplate());
    }

    public function testPreservesTemplateAndExposesVariablesWhenShouldRenderIsTrue(): void
    {
        $component = new class extends AbstractBlockComponent {
            protected function defaults(): array
            {
                return ['tag' => 'h2'];
            }

            public function shouldRender(): bool
            {
                return $this->has('title');
            }
        };
        $component->load(data: ['title' => 'Visible Title']);

        $mounted = new MountedComponent('Headline', $component, new ComponentAttributes([], new EscaperRuntime()));
        $metadata = new ComponentMetadata([
            'key' => 'Headline',
            'template' => '@SuluPrelineBlocks/components/Headline.html.twig',
        ]);
        $event = new PreRenderEvent($mounted, $metadata, []);

        $subscriber = new BlockComponentPreRenderSubscriber();
        $subscriber->onPreRender($event);

        self::assertSame('@SuluPrelineBlocks/components/Headline.html.twig', $event->getTemplate());
        $vars = $event->getVariables();
        self::assertSame('Visible Title', $vars['title'] ?? null);
    }
}
