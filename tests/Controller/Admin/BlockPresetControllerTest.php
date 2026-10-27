<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Totoglu\SuluBlockSuiteBundle\Controller\Admin\BlockPresetController;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockPresetControllerTest extends TestCase
{
    private function createAllowingSecurityChecker(): SecurityCheckerInterface
    {
        $stub = $this->createStub(SecurityCheckerInterface::class);
        $stub->method('hasPermission')->willReturn(true);

        return $stub;
    }

    public function testInvokeReturnsSuccessfulJsonResponse(): void
    {
        $mockProvider = $this->createMock(BlockPresetProviderInterface::class);
        $mockProvider->expects($this->once())
            ->method('getPresets')
            ->with('headline', 'tr')
            ->willReturn([
                [
                    'id' => 'hero_title',
                    'title' => 'Hero Başlık',
                    'description' => 'Test açıklama',
                    'props' => ['tag' => 'h1'],
                ],
            ]);

        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request(['locale' => 'tr']);

        $response = $controller($request, 'headline');

        $this->assertSame(JsonResponse::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        /** @var array{block_type: string, locale: string, presets: list<mixed>} $data */
        $data = \json_decode($content, true);

        $this->assertSame('headline', $data['block_type']);
        $this->assertSame('tr', $data['locale']);
        $this->assertCount(1, $data['presets']);
    }

    public function testInvokeReturnsBadRequestOnInvalidBlockType(): void
    {
        $mockProvider = $this->createMock(BlockPresetProviderInterface::class);
        $mockProvider->expects($this->never())->method('getPresets');

        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request();

        $response = $controller($request, 'invalid/path');

        $this->assertSame(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReusableBlockReturnsNotFoundWhenRepositoryIsNull(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request();

        $response = $controller->reusableBlock($request, 'uuid-123');
        $this->assertSame(JsonResponse::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReusableBlockReturnsNotFoundOnInvalidId(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockRepo = $this->createStub(SnippetRepositoryInterface::class);
        $controller = new BlockPresetController($mockProvider, $mockRepo, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request();

        $response = $controller->reusableBlock($request, 'invalid/uuid/!');
        $this->assertSame(JsonResponse::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testBlockTypesReturnsAvailableBlocks(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockProvider->method('getPresets')->willReturn([]);

        $tempDir = \sys_get_temp_dir() . '/block_types_test_' . \uniqid('', true);
        \mkdir($tempDir, 0777, true);
        \file_put_contents($tempDir . '/card.yaml', 'presets: []');
        \file_put_contents($tempDir . '/headline.yaml', 'presets: []');

        $visitor = new BlockSuiteFormMetadataVisitor($tempDir);

        $controller = new BlockPresetController($mockProvider, null, null, $visitor, null, $this->createAllowingSecurityChecker());
        $request = new Request(['locale' => 'en']);

        $response = $controller->blockTypes($request);
        $this->assertSame(JsonResponse::HTTP_OK, $response->getStatusCode());

        $content = (string) $response->getContent();
        /** @var mixed $data */
        $data = \json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('blocks', $data);
        $this->assertIsArray($data['blocks']);
        $this->assertCount(2, $data['blocks']);
        /** @var array{type: string, title: string, presets_count: int} $firstBlock */
        $firstBlock = $data['blocks'][0];
        $this->assertSame('card', $firstBlock['type']);
        $this->assertSame('Card', $firstBlock['title']);
        /** @var array{type: string, title: string, presets_count: int} $secondBlock */
        $secondBlock = $data['blocks'][1];
        $this->assertSame('headline', $secondBlock['type']);

        // cleanup
        \unlink($tempDir . '/card.yaml');
        \unlink($tempDir . '/headline.yaml');
        \rmdir($tempDir);
    }

    public function testSavePresetReturnsBadRequestOnInvalidBlockType(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'invalid/type!',
            'title' => 'My Preset',
        ]));

        $response = $controller->savePreset($request);
        $this->assertSame(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSavePresetReturnsUnprocessableEntityOnEmptyTitle(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => '   ',
        ]));

        $response = $controller->savePreset($request);
        $this->assertSame(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testSavePresetReturnsServerErrorWhenMessageBusNotConfigured(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => 'My Preset',
        ]));

        $response = $controller->savePreset($request);
        $this->assertSame(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testSavePresetDispatchesMessagesAndReturnsCreatedResponse(): void
    {
        $mockProvider = $this->createMock(BlockPresetProviderInterface::class);
        $mockProvider->expects($this->once())
            ->method('clearCache')
            ->with('card', 'en');

        $mockSnippet = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('snippet-uuid-1234');

        $mockBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $mockBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use ($mockSnippet): \Symfony\Component\Messenger\Envelope {
                $actualMessage = $message instanceof \Symfony\Component\Messenger\Envelope ? $message->getMessage() : $message;

                return new \Symfony\Component\Messenger\Envelope(
                    $actualMessage,
                    [new \Symfony\Component\Messenger\Stamp\HandledStamp($mockSnippet, 'dummy_handler')],
                );
            });

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            $mockBus,
            $this->createAllowingSecurityChecker(),
        );

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => 'Special Card',
            'description' => 'Test preset desc',
            'locale' => 'en',
            'props' => ['variant' => 'bordered', 'preset' => 'old_id'],
            'values' => ['title' => 'Sample Heading', 'type' => 'card'],
        ]));

        $response = $controller->savePreset($request);
        $this->assertSame(JsonResponse::HTTP_CREATED, $response->getStatusCode());

        $content = (string) $response->getContent();
        /** @var array{id: string, title: string, description: ?string, props: array<string, mixed>, values: array<string, mixed>} $data */
        $data = \json_decode($content, true);

        $this->assertSame('snippet_snippet-uuid-1234', $data['id']);
        $this->assertSame('Special Card', $data['title']);
        $this->assertSame('Test preset desc', $data['description']);
        $this->assertArrayNotHasKey('preset', $data['props']);
        $this->assertSame('bordered', $data['props']['variant']);
        $this->assertSame('card', $data['values']['type']);
        $this->assertSame('Sample Heading', $data['values']['title']);
    }

    public function testSavePresetChecksPermission(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockSecurityChecker = $this->createMock(SecurityCheckerInterface::class);
        $mockSecurityChecker->expects($this->once())
            ->method('checkPermission')
            ->with(
                $this->callback(static function (\Sulu\Component\Security\Authorization\SecurityCondition $cond): bool {
                    return 'sulu.snippet.snippets' === $cond->getSecurityContext() && 'tr' === $cond->getLocale();
                }),
                \Sulu\Component\Security\Authorization\PermissionTypes::ADD,
            )
            ->willThrowException(new AccessDeniedException());

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            null,
            $mockSecurityChecker,
        );

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => 'My Preset',
            'locale' => 'tr',
        ]));

        $this->expectException(AccessDeniedException::class);
        $controller->savePreset($request);
    }

    public function testSaveReusableBlockReturnsUnprocessableEntityOnEmptyTitle(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => '',
            'block' => ['type' => 'card'],
        ]));

        $response = $controller->saveReusableBlock($request);
        $this->assertSame(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testSaveReusableBlockReturnsBadRequestOnMissingBlock(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => 'Common Card',
            'block' => [],
        ]));

        $response = $controller->saveReusableBlock($request);
        $this->assertSame(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSaveReusableBlockReturnsInternalServerErrorWhenMessageBusMissing(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());
        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => 'Common Card',
            'block' => ['type' => 'card', 'title' => 'Header'],
        ]));

        $response = $controller->saveReusableBlock($request);
        $this->assertSame(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testSaveReusableBlockDispatchesMessagesAndReturnsCreatedResponse(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);

        $mockSnippet = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('reusable-uuid-9876');

        $mockBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $mockBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use ($mockSnippet): \Symfony\Component\Messenger\Envelope {
                $actualMessage = $message instanceof \Symfony\Component\Messenger\Envelope ? $message->getMessage() : $message;

                return new \Symfony\Component\Messenger\Envelope(
                    $actualMessage,
                    [new \Symfony\Component\Messenger\Stamp\HandledStamp($mockSnippet, 'dummy_handler')],
                );
            });

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            $mockBus,
            $this->createAllowingSecurityChecker(),
        );

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => 'Global Hero Headline',
            'description' => 'Reusable hero across pages',
            'locale' => 'en',
            'block' => [
                'type' => 'headline',
                'preset' => 'old_preset',
                'props' => ['level' => 'h2', 'preset' => 'old_preset'],
                'content' => 'Welcome',
            ],
        ]));

        $response = $controller->saveReusableBlock($request);
        $this->assertSame(JsonResponse::HTTP_CREATED, $response->getStatusCode());

        $content = (string) $response->getContent();
        /** @var array{id: string, title: string, description: ?string, block_count: int} $data */
        $data = \json_decode($content, true);

        $this->assertSame('reusable-uuid-9876', $data['id']);
        $this->assertSame('Global Hero Headline', $data['title']);
        $this->assertSame('Reusable hero across pages', $data['description']);
        $this->assertSame(1, $data['block_count']);
    }

    public function testSaveReusableBlockChecksPermission(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockSecurityChecker = $this->createMock(SecurityCheckerInterface::class);
        $mockSecurityChecker->expects($this->once())
            ->method('checkPermission')
            ->with(
                $this->callback(static function (\Sulu\Component\Security\Authorization\SecurityCondition $cond): bool {
                    return 'sulu.snippet.snippets' === $cond->getSecurityContext() && 'tr' === $cond->getLocale();
                }),
                \Sulu\Component\Security\Authorization\PermissionTypes::ADD,
            )
            ->willThrowException(new AccessDeniedException());

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            null,
            $mockSecurityChecker,
        );

        $request = new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => 'Ortak Blok',
            'locale' => 'tr',
            'block' => ['type' => 'card'],
        ]));

        $this->expectException(AccessDeniedException::class);
        $controller->saveReusableBlock($request);
    }

    public function testGetEndpointsCheckViewPermissionAndStatsExcludedFromReusableBlockRoute(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockSecurityChecker = $this->createMock(SecurityCheckerInterface::class);
        $mockSecurityChecker->expects($this->exactly(3))
            ->method('checkPermission')
            ->with(
                $this->callback(static function (\Sulu\Component\Security\Authorization\SecurityCondition $cond): bool {
                    return 'sulu.snippet.snippets' === $cond->getSecurityContext();
                }),
                \Sulu\Component\Security\Authorization\PermissionTypes::VIEW,
            );

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            null,
            $mockSecurityChecker,
        );

        $request = new Request(['locale' => 'en']);
        $controller->blockTypes($request);
        $controller->presets($request, 'card');
        $res = $controller->reusableBlock($request, 'stats');
        $this->assertSame(JsonResponse::HTTP_NOT_FOUND, $res->getStatusCode());

        $refMethod = new \ReflectionMethod(BlockPresetController::class, 'reusableBlock');
        $routeAttrs = $refMethod->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);
        $this->assertCount(1, $routeAttrs);
        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $routeAttrs[0]->newInstance();
        $requirements = $route->requirements;
        $this->assertArrayHasKey('id', $requirements);
        $idPattern = $requirements['id'];
        $this->assertIsString($idPattern);
        $this->assertSame(0, \preg_match('~' . $idPattern . '~', 'stats'));
        $this->assertSame(1, \preg_match('~' . $idPattern . '~', '019a0000-0000-7000-8000-000000000001'));
    }

    public function testEndpointsThrowAccessDeniedWhenSecurityCheckerIsNull(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider);

        $this->expectException(AccessDeniedException::class);
        $controller->presets(new Request(['locale' => 'en']), 'card');
    }

    public function testInvalidLocaleReturnsBadRequest(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());

        $response = $controller->presets(new Request(['locale' => '../etc/passwd']), 'card');
        $this->assertSame(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());

        $saveResponse = $controller->savePreset(new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => 'Preset',
            'locale' => 'en; DROP TABLE',
        ])));
        $this->assertSame(JsonResponse::HTTP_BAD_REQUEST, $saveResponse->getStatusCode());
    }

    public function testSavePresetSanitizesInternalExceptionDetails(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $mockBus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $mockBus->method('dispatch')->willThrowException(new \RuntimeException('SQLSTATE[42S02]: secret table missing'));

        $controller = new BlockPresetController(
            $mockProvider,
            null,
            null,
            null,
            $mockBus,
            $this->createAllowingSecurityChecker(),
        );

        $response = $controller->savePreset(new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => 'My Preset',
            'locale' => 'en',
        ])));

        $this->assertSame(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    public function testSavePresetAndSaveReusableBlockValidateTitleLengthBlockTypeAndPreviewImage(): void
    {
        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController($mockProvider, null, null, null, null, $this->createAllowingSecurityChecker());

        $longTitle = \str_repeat('a', 256);

        $presetTooLongResponse = $controller->savePreset(new Request([], [], [], [], [], [], (string) \json_encode([
            'block_type' => 'card',
            'title' => $longTitle,
        ])));
        $this->assertSame(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $presetTooLongResponse->getStatusCode());

        $reusableTooLongResponse = $controller->saveReusableBlock(new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => $longTitle,
            'block' => ['type' => 'card'],
        ])));
        $this->assertSame(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $reusableTooLongResponse->getStatusCode());

        $reusableInvalidTypeResponse = $controller->saveReusableBlock(new Request([], [], [], [], [], [], (string) \json_encode([
            'title' => 'Valid Title',
            'block' => ['type' => 'invalid/block!'],
        ])));
        $this->assertSame(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $reusableInvalidTypeResponse->getStatusCode());
    }

    public function testReusableBlocksListFiltersByWebspace(): void
    {
        $totogluSnippet = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetInterface::class);
        $totogluSnippet->method('getUuid')->willReturn('reusable-totoglu');

        $totogluUnloc = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface::class);
        $totogluUnloc->method('getVersion')->willReturn(0);
        $totogluUnloc->method('getLocale')->willReturn(null);
        $totogluUnloc->method('getStage')->willReturn('draft');
        $totogluUnloc->method('getTemplateKey')->willReturn('reusable_block');
        $totogluUnloc->method('getTemplateData')->willReturn(['webspace' => 'totoglu']);

        $totogluLoc = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface::class);
        $totogluLoc->method('getVersion')->willReturn(0);
        $totogluLoc->method('getLocale')->willReturn('en');
        $totogluLoc->method('getStage')->willReturn('draft');
        $totogluLoc->method('getTemplateKey')->willReturn('reusable_block');
        $totogluLoc->method('getTitle')->willReturn('Totoglu Reusable CTA');
        $totogluLoc->method('getTemplateData')->willReturn([
            'blocks' => [['type' => 'cta']],
        ]);
        $totogluSnippet->method('getDimensionContents')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$totogluUnloc, $totogluLoc]),
        );

        $globalSnippet = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetInterface::class);
        $globalSnippet->method('getUuid')->willReturn('reusable-global');

        $globalLoc = $this->createStub(\Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface::class);
        $globalLoc->method('getVersion')->willReturn(0);
        $globalLoc->method('getLocale')->willReturn('en');
        $globalLoc->method('getStage')->willReturn('draft');
        $globalLoc->method('getTemplateKey')->willReturn('reusable_block');
        $globalLoc->method('getTitle')->willReturn('Global Reusable CTA');
        $globalLoc->method('getTemplateData')->willReturn([
            'blocks' => [['type' => 'cta']],
        ]);
        $globalSnippet->method('getDimensionContents')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$globalLoc]),
        );

        $repo = $this->createStub(\Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$totogluSnippet, $globalSnippet]);

        $mockProvider = $this->createStub(BlockPresetProviderInterface::class);
        $controller = new BlockPresetController(
            $mockProvider,
            $repo,
            null,
            null,
            null,
            $this->createAllowingSecurityChecker(),
        );

        $totogluResponse = $controller->reusableBlocks(new Request(['locale' => 'en', 'webspace' => 'totoglu']));
        $this->assertSame(JsonResponse::HTTP_OK, $totogluResponse->getStatusCode());
        /** @var array{_embedded: array{snippets: list<array{id: string}>}, total: int} $totogluBody */
        $totogluBody = \json_decode((string) $totogluResponse->getContent(), true);
        $this->assertSame(2, $totogluBody['total']);

        $turkiyeResponse = $controller->reusableBlocks(new Request(['locale' => 'en', 'webspace' => 'turkiye']));
        $this->assertSame(JsonResponse::HTTP_OK, $turkiyeResponse->getStatusCode());
        /** @var array{_embedded: array{snippets: list<array{id: string}>}, total: int} $turkiyeBody */
        $turkiyeBody = \json_decode((string) $turkiyeResponse->getContent(), true);
        $this->assertSame(1, $turkiyeBody['total']);
        $this->assertSame('reusable-global', $turkiyeBody['_embedded']['snippets'][0]['id']);
    }
}
