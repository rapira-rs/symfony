<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Symfony\Runtime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Runtime extended the way a bundle would, answering a failure that escaped the kernel through the
 * closure passed as the `failure_page` option.
 */
final class FailurePageRuntime extends Runtime
{
    #[\Override]
    protected function createFailureResponse(\Throwable $exception, Request $request): Response
    {
        /** @var \Closure(\Throwable, Request): Response $page */
        $page = $this->options['failure_page'];

        return $page($exception, $request);
    }
}
