<?php

declare(strict_types=1);

use Rapira\Symfony\Runtime;
use Rapira\Symfony\Tests\App\Kernel;

// An application selects the Runtime in its own composer.json; this repository's root is the library.
$_SERVER['APP_RUNTIME'] = Runtime::class;

require_once \dirname(__DIR__, 3) . '/vendor/autoload_runtime.php';

return static fn(): Kernel => new Kernel();
