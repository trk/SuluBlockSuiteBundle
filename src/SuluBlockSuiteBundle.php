<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SuluBlockSuiteBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
