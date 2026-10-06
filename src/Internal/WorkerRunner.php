<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Serves Rapira's Worker mode: the loop over `Rapira\handle_request()` with one resident kernel.
 *
 * @internal
 */
final readonly class WorkerRunner implements RunnerInterface
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpKernelInterface $kernel,
    ) {}

    #[\Override]
    public function run(): int
    {
        \ignore_user_abort(true);

        $boot = BootServer::variables($_SERVER, script: true);
        while ($this->serveNext($boot)) {
            \gc_collect_cycles();
        }

        return 0;
    }

    /**
     * Serves one request, if the host has one, and terminates it. The request and the response die with
     * this frame, so the cycle collection that follows can free them.
     *
     * @param array<string, mixed> $boot
     *
     * @return bool False once the host hands out no more requests.
     */
    private function serveNext(array $boot): bool
    {
        /** @var Request|null $request */
        $request = null;
        /** @var Response|null $response */
        $response = null;

        $served = \Rapira\handle_request(function () use ($boot, &$request, &$response): bool {
            $_SERVER += $boot;

            $request = Request::createFromGlobals();
            $response = $this->kernel->handle($request);
            SapiResponse::send($response);
            rapira_finish_request();

            return true;
        });

        // Outside the handler: the host would answer a failure thrown in it with a 500 for a request it has
        // already sent, and keep serving with a kernel that failed to terminate.
        if ($request !== null && $response !== null && $this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        return $served;
    }
}
