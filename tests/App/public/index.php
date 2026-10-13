<?php

declare(strict_types=1);

use Rapira\Symfony\Runtime;
use Rapira\Symfony\Tests\App\Kernel;

// An application selects the Runtime in its own composer.json; this repository's root is the library.
$_SERVER['APP_RUNTIME'] = Runtime::class;

// Old vendor releases emit PHP 8.4+ deprecations before Symfony installs its error handler.
// Suppress only those boot diagnostics so they do not become part of the HTTP response.
\set_error_handler(static fn(int $severity, string $message, string $file): bool =>
    $severity === E_DEPRECATED && \str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR));

require_once \dirname(__DIR__, 3) . '/vendor/autoload_runtime.php';

return static fn(): Kernel => new Kernel();
