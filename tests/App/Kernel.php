<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\App;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * The smallest HTTP kernel the acceptance suite can serve through the Runtime in every Rapira mode.
 */
final class Kernel implements HttpKernelInterface, TerminableInterface
{
    /** Tells one boot of the application from the next: a resident worker keeps it across requests. */
    private readonly string $bootId;

    /**
     * What the last terminate() saw, for `/termination` to report.
     *
     * @var array<string, mixed>
     */
    private array $lastTermination = [];

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
            '/upload' => new Response('uploaded'),
            '/termination' => new JsonResponse($this->lastTermination),
            '/terminate-timeout' => $this->timedResponse(),
            '/broken-stream' => new StreamedResponse(static function (): never {
                echo 'partial';
                \ob_flush();

                throw new \RuntimeException('stream failed after its head');
            }),
            '/stream' => new StreamedResponse(static function (): void {
                echo 'one';
                \ob_flush();
                \flush();
                echo 'two';
            }),
            default => new Response('Not Found', Response::HTTP_NOT_FOUND),
        };
    }

    #[\Override]
    public function terminate(Request $request, Response $response): void
    {
        if ($request->getPathInfo() === '/broken-stream') {
            $this->lastTermination = ['stream' => true];
        }

        if ($request->getPathInfo() === '/upload') {
            $file = $request->files->get('file');
            $exists = $file !== null && \is_file($file->getPathname());
            $this->lastTermination = [
                'exists' => $exists,
                'content' => $exists ? \file_get_contents($file->getPathname()) : null,
            ];
        }

        if ($request->getPathInfo() === '/terminate-timeout') {
            // CPU work must hit max_execution_time. Bound it so a regression cannot hang CI.
            $deadline = \microtime(true) + 3.0;
            while (\microtime(true) < $deadline) {
            }
            \set_time_limit(0);
        }
    }

    private function timedResponse(): Response
    {
        \set_time_limit(1);

        return new Response($this->bootId);
    }
}
