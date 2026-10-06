<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\ClosedException;
use Rapira\Http\HttpDispatcher;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * @internal
 */
final readonly class DispatcherRunner implements RunnerInterface
{
    private DispatcherRequestFactory $requestFactory;

    public function __construct(
        private HttpKernelInterface $kernel,
        private HttpDispatcher $dispatcher,
    ) {
        $this->requestFactory = new DispatcherRequestFactory();
    }

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
                if ($exchange->isCancelled()) {
                    continue;
                }

                $converted = $this->requestFactory->create($exchange->getRequest());
                $request = $converted->request;
                $response = $this->kernel->handle($request);

                try {
                    (new ExchangeResponseEmitter($exchange))->emit($request, $response);
                } catch (ResponseDiscardedException) {
                }

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($request, $response);
                }
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
