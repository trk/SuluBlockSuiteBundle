<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\EventSubscriber;

use Sulu\Bundle\HttpCacheBundle\Cache\CacheManagerInterface;
use Sulu\Snippet\Domain\Event\SnippetModifiedEvent;
use Sulu\Snippet\Domain\Event\SnippetRemovedEvent;
use Sulu\Snippet\Domain\Event\SnippetWorkflowTransitionAppliedEvent;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Automatically invalidates reverse-proxy HTTP cache tags and references when a reusable block
 * or page layout snippet is modified, published/unpublished, or removed.
 */
final class ReusableBlockCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?CacheManagerInterface $cacheManager = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SnippetModifiedEvent::class => 'onSnippetModified',
            SnippetWorkflowTransitionAppliedEvent::class => 'onSnippetWorkflowTransition',
            SnippetRemovedEvent::class => 'onSnippetRemoved',
        ];
    }

    public function onSnippetModified(SnippetModifiedEvent $event): void
    {
        $this->invalidateSnippetUuid($event->getSnippet()->getUuid());
    }

    public function onSnippetWorkflowTransition(SnippetWorkflowTransitionAppliedEvent $event): void
    {
        $this->invalidateSnippetUuid($event->getSnippet()->getUuid());
    }

    public function onSnippetRemoved(SnippetRemovedEvent $event): void
    {
        $this->invalidateSnippetUuid($event->getResourceId());
    }

    private function invalidateSnippetUuid(string $uuid): void
    {
        if (null === $this->cacheManager || '' === \trim($uuid)) {
            return;
        }

        $this->cacheManager->invalidateTag($uuid);
        $this->cacheManager->invalidateReference(SnippetInterface::RESOURCE_KEY, $uuid);
    }
}
