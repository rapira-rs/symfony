<?php

declare(strict_types=1);

namespace Rapira\Symfony;

use Rapira\Symfony\Internal\RequestLoop;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

final readonly class Runner implements RunnerInterface
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpKernelInterface $kernel,
        private RequestLoop $requestLoop,
    ) {}

    #[\Override]
    public function run(): int
    {
        \ignore_user_abort(true);

        /** @var array<string, mixed> $server */
        $server = \array_filter(
            $_SERVER,
            static fn(mixed $key): bool => !\str_starts_with((string) $key, 'HTTP_'),
            \ARRAY_FILTER_USE_KEY,
        );

        while (true) {
            $request = null;
            $response = null;

            $handled = $this->requestLoop->handle(function () use ($server, &$request, &$response): bool {
                $_SERVER += $server;

                $request = Request::createFromGlobals();
                $response = $this->kernel->handle($request);
                $response->send();

                return true;
            });

            if (!$handled) {
                break;
            }

            if ($this->kernel instanceof TerminableInterface && $request !== null && $response !== null) {
                $this->kernel->terminate($request, $response);
            }
        }

        return 0;
    }
}
