<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Serves Rapira's Worker mode: the loop over `Rapira\handle_request()` with one resident kernel.
 *
 * The kernel boots before the first request, so a broken container fails the worker before it takes
 * any, which Rapira counts as a failed start. A failure that escapes the kernel, which answers its own
 * errors, or its terminate() leaves the kernel in an unknown state: the request is answered with the
 * failure response while it still can be, the failure is logged, and the loop ends for Rapira to run
 * the script afresh.
 *
 * @internal
 */
final readonly class WorkerRunner implements RunnerInterface
{
    /**
     * @param \Closure(\Throwable, Request): Response $failureResponse
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpKernelInterface $kernel,
        private \Closure $failureResponse,
    ) {}

    #[\Override]
    public function run(): int
    {
        \ignore_user_abort(true);

        $boot = BootServer::variables($_SERVER, script: true);
        $this->kernel instanceof KernelInterface and $this->kernel->boot();

        try {
            while ($this->serveNext($boot)) {
                // TODO: a full collection after every request can cost more than the request on a large
                //  application; make it configurable, see https://github.com/rapira-rs/sdk-php/issues/14
                \gc_collect_cycles();
            }
        } catch (\Throwable $exception) {
            \Rapira\log('The worker stops serving after a failure it cannot answer for.', LogLevel::Error, ['exception' => $exception]);

            return 1;
        }

        return 0;
    }

    /**
     * Serves one request, if the host has one, and terminates it. The request and the response die with
     * the handler, so the cycle collection that follows can free them.
     *
     * @param array<string, mixed> $boot
     *
     * @return bool False once the host hands out no more requests.
     *
     * @throws \Throwable A failure of the kernel, once its request is answered, or of terminate().
     */
    private function serveNext(array $boot): bool
    {
        /** @var \Throwable|null $failure */
        $failure = null;

        $served = \Rapira\handle_request(function () use ($boot, &$failure): bool {
            $_SERVER += $boot;

            $request = Request::createFromGlobals();
            try {
                $response = $this->kernel->handle($request);
            } catch (\Throwable $exception) {
                // Thrown out of the handler, it would reach the client as the host's bare 500.
                $failure = $exception;
                $this->answerFailure($exception, $request);
                rapira_finish_request();

                return true;
            }

            SapiResponse::send($response);
            rapira_finish_request();

            // Keep the host's timeout and uploaded files alive until termination is complete.
            // Rethrow only after handle_request() returns: the client already has its response.
            if ($this->kernel instanceof TerminableInterface) {
                try {
                    $this->kernel->terminate($request, $response);
                } catch (\Throwable $exception) {
                    $failure = $exception;
                }
            }

            return true;
        });

        $failure === null or throw $failure;

        return $served;
    }

    /**
     * Sends the failure response, or a plain 500 if it cannot be built or sent. Once output is out, the
     * head is gone with it and nothing more is sent.
     */
    private function answerFailure(\Throwable $exception, Request $request): void
    {
        try {
            if (!\headers_sent()) {
                SapiResponse::send(($this->failureResponse)($exception, $request));
            }
        } catch (\Throwable) {
            \headers_sent() or SapiResponse::send(new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
    }
}
