<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent;

/**
 * Short-circuits Twig Component rendering when an `AbstractBlockComponent::shouldRender()`
 * returns `false`, and exposes normalized component variables when `true`.
 */
final class BlockComponentPreRenderSubscriber implements EventSubscriberInterface
{
    public const EMPTY_TEMPLATE = '@SuluBlockSuite/components/_empty.html.twig';

    public static function getSubscribedEvents(): array
    {
        return [
            PreRenderEvent::class => ['onPreRender', 100],
        ];
    }

    public function onPreRender(PreRenderEvent $event): void
    {
        $component = $event->getComponent();
        if (!$component instanceof AbstractBlockComponent) {
            return;
        }

        if (!$component->shouldRender()) {
            $event->setTemplate(self::EMPTY_TEMPLATE);
            $event->setVariables([]);

            return;
        }

        $event->setVariables([
            ...$component->getTemplateVariables(),
            ...$event->getVariables(),
        ]);
    }
}
