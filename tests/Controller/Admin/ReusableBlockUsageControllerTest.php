<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Totoglu\SuluBlockSuiteBundle\Controller\Admin\ReusableBlockUsageController;

final class ReusableBlockUsageControllerTest extends TestCase
{
    private function createAllowingSecurityChecker(): SecurityCheckerInterface
    {
        $stub = $this->createStub(SecurityCheckerInterface::class);
        $stub->method('hasPermission')->willReturn(true);

        return $stub;
    }

    public function testUsageReturnsSuccessfulData(): void
    {
        $mockRepo = $this->createMock(ReferenceRepositoryInterface::class);
        $mockRepo->expects($this->once())
            ->method('count')
            ->with(
                ['resourceKey' => SnippetInterface::RESOURCE_KEY, 'resourceId' => 'snippet-123'],
                ['referenceResourceId'],
            )
            ->willReturn(2);

        $mockRepo->expects($this->once())
            ->method('findFlatBy')
            ->willReturn([
                [
                    'referenceResourceKey' => 'pages',
                    'referenceResourceId' => 'page-1',
                    'referenceLocale' => 'en',
                    'referenceTitle' => 'Home Page',
                    'referenceProperty' => 'blocks/0/snippet',
                ],
            ]);

        $controller = new ReusableBlockUsageController($mockRepo, $this->createAllowingSecurityChecker());
        $request = new Request();

        $response = $controller->usage($request, 'snippet-123');
        $this->assertSame(JsonResponse::HTTP_OK, $response->getStatusCode());

        /** @var array{id: string, usage_count: int, is_used: bool, references: list<array{resource_key: string, title: string, edit_url: string, router_attributes: array<string, mixed>}>} $data */
        $data = \json_decode((string) $response->getContent(), true);

        $this->assertSame('snippet-123', $data['id']);
        $this->assertSame(2, $data['usage_count']);
        $this->assertTrue($data['is_used']);
        $this->assertCount(1, $data['references']);
        $this->assertSame('pages', $data['references'][0]['resource_key']);
        $this->assertSame('Home Page', $data['references'][0]['title']);
        $this->assertSame('/admin/#/pages/en/page-1/content', $data['references'][0]['edit_url']);
        $this->assertSame([], $data['references'][0]['router_attributes']);
    }

    public function testUsageWithCustomBasePath(): void
    {
        $mockRepo = $this->createStub(ReferenceRepositoryInterface::class);
        $mockRepo->method('count')->willReturn(1);
        $mockRepo->method('findFlatBy')->willReturn([
            [
                'referenceResourceKey' => 'snippets',
                'referenceResourceId' => 'snippet-99',
                'referenceLocale' => 'tr',
                'referenceTitle' => 'Alt Bilgi',
                'referenceProperty' => 'snippet',
                'referenceRouterAttributes' => ['webspace' => 'website'],
            ],
        ]);

        $controller = new ReusableBlockUsageController($mockRepo, $this->createAllowingSecurityChecker());
        $request = Request::create(
            '/subsite/admin/api/reusable-blocks/s-1/usage',
            server: [
                'SCRIPT_NAME' => '/subsite/index.php',
                'SCRIPT_FILENAME' => '/var/www/subsite/index.php',
                'PHP_SELF' => '/subsite/index.php',
            ],
        );

        $response = $controller->usage($request, 's-1');
        $this->assertSame(JsonResponse::HTTP_OK, $response->getStatusCode());

        /** @var array{references: list<array{resource_key: string, edit_url: string, router_attributes: array<string, string>}>} $data */
        $data = \json_decode((string) $response->getContent(), true);

        $this->assertCount(1, $data['references']);
        $this->assertSame('/subsite/admin/#/snippets/tr/snippet-99/content', $data['references'][0]['edit_url']);
        $this->assertSame(['webspace' => 'website'], $data['references'][0]['router_attributes']);
    }

    public function testUsageForbiddenWhenSecurityFails(): void
    {
        $mockSecurity = $this->createMock(SecurityCheckerInterface::class);
        $mockSecurity->expects($this->once())
            ->method('hasPermission')
            ->with(
                $this->callback(static function (\Sulu\Component\Security\Authorization\SecurityCondition $condition): bool {
                    return SnippetAdmin::SECURITY_CONTEXT === $condition->getSecurityContext()
                        && 'tr' === $condition->getLocale();
                }),
                'view',
            )
            ->willReturn(false);

        $controller = new ReusableBlockUsageController(null, $mockSecurity);
        $response = $controller->usage(new Request(['locale' => 'tr']), 'snippet-123');

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testUsageAndStatsForbiddenWhenSecurityCheckerIsNull(): void
    {
        $controller = new ReusableBlockUsageController();

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->usage(new Request(), 'snippet-123')->getStatusCode());
        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->stats(new Request(['ids' => 'id-1']))->getStatusCode());
    }

    public function testUsageBadRequestOnInvalidId(): void
    {
        $controller = new ReusableBlockUsageController(null, $this->createAllowingSecurityChecker());
        $response = $controller->usage(new Request(), 'invalid/id#bad');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testStatsReturnsBatchCountsAndDeduplicatesIds(): void
    {
        $mockRepo = $this->createMock(ReferenceRepositoryInterface::class);
        $mockRepo->expects($this->exactly(2))
            ->method('count')
            ->willReturnMap([
                [['resourceKey' => SnippetInterface::RESOURCE_KEY, 'resourceId' => 'id-1'], ['referenceResourceId'], 3],
                [['resourceKey' => SnippetInterface::RESOURCE_KEY, 'resourceId' => 'id-2'], ['referenceResourceId'], 0],
            ]);

        $controller = new ReusableBlockUsageController($mockRepo, $this->createAllowingSecurityChecker());
        $request = new Request(['ids' => 'id-1,id-2,id-1']);

        $response = $controller->stats($request);
        $this->assertSame(JsonResponse::HTTP_OK, $response->getStatusCode());

        /** @var array{counts: array<string, int>} $data */
        $data = \json_decode((string) $response->getContent(), true);
        $this->assertSame(['id-1' => 3, 'id-2' => 0], $data['counts']);
    }

    public function testStatsReturnsEmptyWhenNoIds(): void
    {
        $controller = new ReusableBlockUsageController(null, $this->createAllowingSecurityChecker());
        $response = $controller->stats(new Request());

        /** @var array{counts: array<string, int>} $data */
        $data = \json_decode((string) $response->getContent(), true);
        $this->assertSame([], $data['counts']);
    }
}
