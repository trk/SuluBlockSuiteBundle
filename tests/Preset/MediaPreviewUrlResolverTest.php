<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\MediaPreviewUrlResolver;

final class MediaPreviewUrlResolverTest extends TestCase
{
    public function testResolveNullReturnsNull(): void
    {
        $resolver = new MediaPreviewUrlResolver();
        $this->assertNull($resolver->resolve(null, 'en'));
    }

    public function testResolveDirectUrlString(): void
    {
        $resolver = new MediaPreviewUrlResolver();
        $this->assertSame('/uploads/custom-preview.jpg', $resolver->resolve('/uploads/custom-preview.jpg', 'en'));
    }

    public function testResolveMediaIdWithPreferredFormat(): void
    {
        $media = $this->createStub(Media::class);
        $media->method('getFormats')->willReturn([
            'sulu-260x' => '/media/cache/sulu-260x/preview.jpg',
            'sulu-100x100' => '/media/cache/sulu-100x100/preview.jpg',
        ]);
        $media->method('getUrl')->willReturn('/media/preview.jpg');

        $mediaManager = $this->createMock(MediaManagerInterface::class);
        $mediaManager->expects($this->once())
            ->method('getById')
            ->with(42, 'tr')
            ->willReturn($media);

        $resolver = new MediaPreviewUrlResolver($mediaManager);
        $this->assertSame('/media/cache/sulu-260x/preview.jpg', $resolver->resolve(42, 'tr'));
    }

    public function testResolveMediaIdWithFallbackFormat(): void
    {
        $media = $this->createStub(Media::class);
        $media->method('getFormats')->willReturn([
            'sulu-100x100' => '/media/cache/sulu-100x100/thumb.jpg',
        ]);
        $media->method('getUrl')->willReturn('/media/thumb.jpg');

        $mediaManager = $this->createMock(MediaManagerInterface::class);
        $mediaManager->expects($this->once())
            ->method('getById')
            ->with(55, 'en')
            ->willReturn($media);

        $resolver = new MediaPreviewUrlResolver($mediaManager);
        $this->assertSame('/media/cache/sulu-100x100/thumb.jpg', $resolver->resolve('55', 'en'));
    }

    public function testResolveMediaArrayWithId(): void
    {
        $media = $this->createStub(Media::class);
        $media->method('getFormats')->willReturn([]);
        $media->method('getUrl')->willReturn('/media/original.png');

        $mediaManager = $this->createMock(MediaManagerInterface::class);
        $mediaManager->expects($this->once())
            ->method('getById')
            ->with(99, 'en')
            ->willReturn($media);

        $resolver = new MediaPreviewUrlResolver($mediaManager);
        $this->assertSame('/media/original.png', $resolver->resolve(['id' => 99], 'en'));
    }

    public function testResolveHandlesMediaExceptionGracefully(): void
    {
        $mediaManager = $this->createMock(MediaManagerInterface::class);
        $mediaManager->expects($this->once())
            ->method('getById')
            ->with(123, 'en')
            ->willThrowException(new \RuntimeException('Media not found'));

        $resolver = new MediaPreviewUrlResolver($mediaManager);
        $this->assertNull($resolver->resolve(123, 'en'));
    }
}
