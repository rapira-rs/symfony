<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\GrpcDispatcher;
use Rapira\Grpc\Status;
use Rapira\Grpc\StatusCode;
use Rapira\Grpc\UnaryCall;
use Rapira\LogLevel;
use Rapira\Symfony\Grpc\CallDiscardedEvent;
use Rapira\Symfony\Grpc\UnaryCallHandlerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Serves native unary gRPC calls through an application service, without an HTTP request or response.
 * A broken handler or reset leaves the application in an unknown state, so the worker stops serving.
 *
 * @internal
 */
final readonly class GrpcRunner implements RunnerInterface
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private KernelInterface $kernel,
        private GrpcDispatcher $dispatcher,
    ) {}

    #[\Override]
    public function run(): int
    {
        $this->kernel->boot();

        try {
            $container = $this->kernel->getContainer();
            $container->has(UnaryCallHandlerInterface::class) or throw new \LogicException(
                'Register a public ' . UnaryCallHandlerInterface::class . ' service to serve gRPC calls.',
            );
            $handler = $container->get(UnaryCallHandlerInterface::class);
            $handler instanceof UnaryCallHandlerInterface or throw new \LogicException(
                'The gRPC handler service must implement ' . UnaryCallHandlerInterface::class . '.',
            );
            $resetter = $container->has('services_resetter') ? $container->get('services_resetter') : null;
            $resetter === null || $resetter instanceof ResetInterface or throw new \LogicException(
                'The services_resetter service must implement ' . ResetInterface::class . '.',
            );
            $events = $container->has('event_dispatcher') ? $container->get('event_dispatcher') : null;
            $events === null || $events instanceof EventDispatcherInterface or throw new \LogicException(
                'The event_dispatcher service must implement ' . EventDispatcherInterface::class . '.',
            );

            while (true) {
                try {
                    $call = $this->dispatcher->receive();
                } catch (ClosedException) {
                    return 0;
                }

                try {
                    if ($call->isCancelled()) {
                        continue;
                    }
                    if ($call instanceof UnaryCall) {
                        self::serve($handler, $call);
                    } else {
                        $call->fail(new Status(StatusCode::Unimplemented, 'Only unary gRPC calls are supported.'));
                    }
                } catch (WorkDiscardedException $exception) {
                    // The host can close a call between a cancellation check and a write.
                    $events?->dispatch(new CallDiscardedEvent($call, $exception));
                    unset($exception);
                } finally {
                    $resetter?->reset();
                    // Do not retain call-scoped objects across the blocking receive().
                    unset($call);
                }
            }
        } catch (\Throwable $exception) {
            \Rapira\log('The gRPC worker stops serving after a failure.', LogLevel::Error, ['exception' => $exception]);

            return 1;
        } finally {
            $this->kernel->shutdown();
        }
    }

    private static function serve(UnaryCallHandlerInterface $handler, UnaryCall $call): void
    {
        try {
            $handler->handle($call);
            if (!$call->isFinalized() && !$call->isCancelled()) {
                throw new \LogicException('The gRPC handler returned without finalizing the call.');
            }
        } catch (WorkDiscardedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            try {
                if (!$call->isFinalized() && !$call->isCancelled()) {
                    $call->fail(new Status(StatusCode::Internal, 'Internal server error.'));
                }
            } finally {
                // A failed error response does not hide the original application failure.
                throw $exception;
            }
        }
    }
}
