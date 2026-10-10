<?php

declare(strict_types=1);

if (\is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (\is_file(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    throw new \RuntimeException('Cannot find vendor/autoload.php. Run composer install.');
}
