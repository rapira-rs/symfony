<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Serves the one request of a script run in Rapira's Classic mode.
 *
 * @internal
 */
final readonly class ClassicRunner implements RunnerInterface
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpKernelInterface $kernel,
        private bool $debug,
    ) {}

    #[\Override]
    public function run(): int
    {
        $request = Request::createFromGlobals();
        $response = $this->kernel->handle($request);

        SapiResponse::send($response);
        // The client has its answer while terminate runs. Outside Rapira the stub answers false, and the
        // request is finished the way Symfony's own runner does it, which keeps it open in debug.
        if (!rapira_finish_request() && !$this->debug) {
            \function_exists('fastcgi_finish_request') and fastcgi_finish_request();
            \function_exists('litespeed_finish_request') and litespeed_finish_request();
        }

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        return 0;
    }
}
