<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The smallest HTTP kernel the acceptance suite can serve through the Runtime in every Rapira mode.
 */
final class Kernel implements HttpKernelInterface
{
    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return match ($request->getPathInfo()) {
            '/' => new Response('OK'),
            '/mode' => new Response(\strtolower(\Rapira\get_mode()->name)),
            default => new Response('Not Found', Response::HTTP_NOT_FOUND),
        };
    }
}
