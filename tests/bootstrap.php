<?php

declare(strict_types=1);

\spl_autoload_register(static function (string $class): void {
    $prefix = 'Totoglu\\SuluBlockSuiteBundle\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \substr($class, \strlen($prefix));
    if (\str_starts_with($relative, 'Tests\\')) {
        $file = __DIR__ . '/' . \str_replace('\\', '/', \substr($relative, 6)) . '.php';
    } else {
        $file = __DIR__ . '/../src/' . \str_replace('\\', '/', $relative) . '.php';
    }

    if (\is_file($file)) {
        require_once $file;
    }
}, true, true);

if (\is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (\is_file(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    throw new \RuntimeException('Cannot find vendor/autoload.php. Run composer install.');
}
