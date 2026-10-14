<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\HttpCacheBundle\Cache\CacheManagerInterface;
use Sulu\Snippet\Domain\Event\SnippetModifiedEvent;
use Sulu\Snippet\Domain\Event\SnippetRemovedEvent;
use Sulu\Snippet\Domain\Event\SnippetWorkflowTransitionAppliedEvent;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Totoglu\SuluBlockSuiteBundle\EventSubscriber\ReusableBlockCacheInvalidationSubscriber;

final class ReusableBlockCacheInvalidationSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = ReusableBlockCacheInvalidationSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(SnippetModifiedEvent::class, $events);
        $this->assertArrayHasKey(SnippetWorkflowTransitionAppliedEvent::class, $events);
        $this->assertArrayHasKey(SnippetRemovedEvent::class, $events);
    }

    public function testOnSnippetModifiedInvalidatesSnippetReferenceAndTag(): void
    {
        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->expects($this->once())
            ->method('invalidateReference')
            ->with(SnippetInterface::RESOURCE_KEY, 'reusable-uuid-123');
        $cacheManager->expects($this->once())
            ->method('invalidateTag')
            ->with('reusable-uuid-123');

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('reusable-uuid-123');
        $snippetEvent = new SnippetModifiedEvent($snippet, 'en', ['title' => 'Hero Reusable']);

        $subscriber = new ReusableBlockCacheInvalidationSubscriber($cacheManager);
        $subscriber->onSnippetModified($snippetEvent);
    }

    public function testOnSnippetRemovedInvalidatesByResourceId(): void
    {
        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->expects($this->once())
            ->method('invalidateReference')
            ->with(SnippetInterface::RESOURCE_KEY, 'removed-uuid-456');
        $cacheManager->expects($this->once())
            ->method('invalidateTag')
            ->with('removed-uuid-456');

        $event = new SnippetRemovedEvent('removed-uuid-456', 'Old Reusable', ['locales' => ['en']]);

        $subscriber = new ReusableBlockCacheInvalidationSubscriber($cacheManager);
        $subscriber->onSnippetRemoved($event);
    }
}
