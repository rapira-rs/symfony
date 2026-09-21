<?php

declare(strict_types=1);

namespace Rapira\Symfony;

use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\HttpDispatcher;
use Rapira\Symfony\Internal\DispatcherRequestFactory;
use Rapira\Symfony\Internal\ExchangeResponseEmitter;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

final readonly class DispatcherRunner implements RunnerInterface
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpKernelInterface $kernel,
        private HttpDispatcher $dispatcher,
        private DispatcherRequestFactory $requestFactory,
        private ExchangeResponseEmitter $responseEmitter,
    ) {}

    #[\Override]
    public function run(): int
    {
        while (true) {
            try {
                $exchange = $this->dispatcher->receive();
            } catch (ClosedException) {
                return 0;
            }

            $converted = null;
            $request = null;
            $response = null;

            try {
                $converted = $this->requestFactory->create($exchange);
                $request = $converted->request;
                $response = $this->kernel->handle($request);
                $this->responseEmitter->emit($exchange, $request, $response);

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($request, $response);
                }
            } catch (WorkDiscardedException) {
            } finally {
                $response = null;
                $request = null;
                $converted?->cleanup();
                $converted = null;
                $exchange = null;
                \gc_collect_cycles();
            }
        }
    }
}
