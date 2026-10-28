<?php

declare(strict_types=1);

use Rapira\Symfony\Runtime;
use Rapira\Symfony\Tests\App\GrpcKernel;

$_SERVER['APP_RUNTIME'] = Runtime::class;
require_once \dirname(__DIR__, 2) . '/vendor/autoload_runtime.php';

return static fn(): GrpcKernel => new GrpcKernel('test', false);
