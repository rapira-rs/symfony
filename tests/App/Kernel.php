<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The smallest HTTP kernel the acceptance suite can serve through the Runtime in every Rapira mode.
 */
final class Kernel implements HttpKernelInterface
{
    /** Tells one boot of the application from the next: a resident worker keeps it across requests. */
    private readonly string $bootId;

    public function __construct()
    {
        $this->bootId = \bin2hex(\random_bytes(8));
    }

    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return match ($request->getPathInfo()) {
            '/' => new Response('OK'),
            '/mode' => new Response(\strtolower(\Rapira\get_mode()->name)),
            '/boot' => new Response($this->bootId),
            '/stream' => new StreamedResponse(static function (): void {
                echo 'one';
                \ob_flush();
                \flush();
                echo 'two';
            }),
            default => new Response('Not Found', Response::HTTP_NOT_FOUND),
        };
    }
}
