<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\LogLevel;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Serves Rapira's Dispatcher mode: takes exchanges from the HTTP dispatcher one at a time and runs each
 * through the resident kernel.
 *
 * A request that cannot be converted, or a response that fails before its head is written, is answered
 * with an error and logged, and the loop goes on. A failure of the kernel, of terminate(), or of a
 * response whose head is out propagates, and the host replaces the worker.
 *
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

            $this->serve($exchange);
            // Held across receive(), an exchange left unfinalized would stay open until the next one arrives.
            $exchange = null;
            \gc_collect_cycles();
        }
    }

    private static function report(string $message, \Throwable $exception): void
    {
        \Rapira\log($message, LogLevel::Error, ['exception' => $exception]);
    }

    private function serve(Exchange $exchange): void
    {
        // The client left or the deadline passed while the exchange waited in the queue.
        if ($exchange->isCancelled()) {
            return;
        }

        $emitter = new ExchangeResponseEmitter($exchange);

        try {
            $converted = $this->requestFactory->create($exchange->getRequest());
        } catch (\Throwable $exception) {
            self::report('The Rapira request could not be converted to a Symfony request.', $exception);
            $emitter->emitError($exception instanceof RequestExceptionInterface ? 400 : 500);

            return;
        }

        try {
            $request = $converted->request;
            $response = $this->kernel->handle($request);

            try {
                $emitter->emit($request, $response);
            } catch (ResponseDiscardedException) {
            } catch (UnsentResponseException $exception) {
                self::report('The Symfony response could not be sent.', $exception->getPrevious() ?? $exception);
                $emitter->emitError(500);
            }

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        } finally {
            $converted->cleanup();
        }
    }
}
