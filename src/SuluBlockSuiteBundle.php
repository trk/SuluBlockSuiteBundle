<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

final class SuluBlockSuiteBundle extends Bundle
{
    public function boot(): void
    {
        parent::boot();

        if (null !== $this->container) {
            $formats = $this->container->getParameter('sulu_block_suite.media.srcset_formats');
            SuluDataNormalizer::setDefaultSrcSetFormats($formats);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
