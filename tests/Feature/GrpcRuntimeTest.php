<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Grpc\Status;
use Rapira\Grpc\StatusCode;
use Rapira\Grpc\UnaryCall;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Symfony\Grpc\CallDiscardedEvent;
use Rapira\Symfony\Grpc\UnaryCallHandlerInterface;
use Rapira\Symfony\Tests\Feature\Fixture\GrpcKernel;
use Rapira\Symfony\Tests\Feature\Stub\CallbackEventDispatcher;
use Rapira\Symfony\Tests\Feature\Stub\CallbackUnaryHandler;
use Rapira\Symfony\Tests\Feature\Stub\RecordingResetter;
use Rapira\Symfony\Tests\Feature\Stub\RecordingUnaryCall;
use Rapira\Symfony\Tests\Feature\Stub\ScriptedGrpcDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class GrpcRuntimeTest
{
    use FakeRuntimeLifecycle;

    public function servesThroughTheContainerAndResetsBeforeReceivingAgain(): void
    {
        $first = new RecordingUnaryCall('first');
        $second = new RecordingUnaryCall('second');
        $dispatcher = new ScriptedGrpcDispatcher($first, $second);
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $state = 0;
        $resetter = new RecordingResetter(static function () use (&$state): void {
            $state = 0;
        });
        $kernel = self::kernel(static function (UnaryCall $call) use (&$state): void {
            ++$state;
            $call->respond($call->getMessage() . ':' . $state);
        }, $resetter);
        $dispatcher->beforeReceive = static function () use (&$state): void {
            Assert::same($state, 0);
        };

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::same($first->response, 'first:1');
        Assert::same($second->response, 'second:1');
        Assert::same($resetter->resets, 2);
        Assert::same($kernel->events, ['boot', 'shutdown']);
        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
    }

    public function applicationRejectionsDoNotStopTheWorkerWithoutOptionalServices(): void
    {
        $rejected = new RecordingUnaryCall('invalid');
        $accepted = new RecordingUnaryCall('valid');
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($rejected, $accepted)))->install();
        $kernel = self::kernel(static function (UnaryCall $call): void {
            if ($call->getMessage() === 'invalid') {
                $call->fail(new Status(StatusCode::InvalidArgument, 'Invalid input.'));
            } else {
                $call->respond('OK');
            }
        });

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::same($rejected->status?->code, StatusCode::InvalidArgument);
        Assert::same($accepted->response, 'OK');
        Assert::same($kernel->events, ['boot', 'shutdown']);
    }

    public function drainedDispatcherShutsDownWithoutReceivingACall(): void
    {
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher()))->install();
        $kernel = self::kernel(static function (): never {
            throw new \LogicException('No call should be handled.');
        });

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::same($kernel->events, ['boot', 'shutdown']);
    }

    public function discardedCallsNotifyListenersBeforeResetAndContinue(): void
    {
        $cancelled = new RecordingUnaryCall(cancelled: true);
        $disconnected = new RecordingUnaryCall(disconnectOnWrite: true);
        $accepted = new RecordingUnaryCall();
        $host = (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($cancelled, $disconnected, $accepted)))->install();
        $order = [];
        $resetter = new RecordingResetter(static function () use (&$order): void {
            $order[] = 'reset';
        });
        $events = new CallbackEventDispatcher(static function (CallDiscardedEvent $event) use ($disconnected, &$order): void {
            Assert::same($event->call, $disconnected);
            Assert::same($event->exception->getMessage(), 'Client left.');
            $order[] = 'discarded';
        });
        $kernel = self::kernel(static fn(UnaryCall $call) => $call->respond('OK'), $resetter, $events);

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::same($order, ['reset', 'discarded', 'reset', 'reset']);
        Assert::same($accepted->response, 'OK');
        Assert::same($host->logs, []);
        Assert::same($kernel->events, ['boot', 'shutdown']);
    }

    public function listenerFailureStillResetsAndShutsDownBeforeTheNextCall(): void
    {
        $call = new RecordingUnaryCall(disconnectOnWrite: true);
        $dispatcher = new ScriptedGrpcDispatcher($call, new RecordingUnaryCall());
        $host = (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $resetter = new RecordingResetter();
        $events = new CallbackEventDispatcher(static function (): never {
            throw new \RuntimeException('Listener failed.');
        });
        $kernel = self::kernel(static fn(UnaryCall $call) => $call->respond('OK'), $resetter, $events);

        Assert::same(self::runtime()->getRunner($kernel)->run(), 1);
        Assert::same($dispatcher->receives, 1);
        Assert::same($resetter->resets, 1);
        Assert::same($kernel->events, ['boot', 'shutdown']);
        Assert::count($host->logs, 1);
    }

    public function cancellationDuringTheHandlerDoesNotTurnIntoAWorkerFailure(): void
    {
        $cancelled = new RecordingUnaryCall();
        $accepted = new RecordingUnaryCall();
        $host = (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($cancelled, $accepted)))->install();
        $kernel = self::kernel(static function (UnaryCall $call) use ($cancelled): void {
            if ($call === $cancelled) {
                $cancelled->cancelled = true;
                return;
            }
            $call->respond('OK');
        });

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::null($cancelled->status);
        Assert::same($accepted->response, 'OK');
        Assert::same($host->logs, []);
    }

    public function cancellationAndDisconnectStillResetAndContinue(): void
    {
        $cancelled = new RecordingUnaryCall(cancelled: true);
        $disconnected = new RecordingUnaryCall(disconnectOnWrite: true);
        $accepted = new RecordingUnaryCall();
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($cancelled, $disconnected, $accepted)))->install();
        $handled = 0;
        $resetter = new RecordingResetter();
        $kernel = self::kernel(static function (UnaryCall $call) use (&$handled): void {
            ++$handled;
            $call->respond('OK');
        }, $resetter);

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
        Assert::same($handled, 2);
        Assert::same($resetter->resets, 3);
        Assert::same($accepted->response, 'OK');
    }

    public function unexpectedFailureIsSanitizedAndStopsAfterReset(): void
    {
        $call = new RecordingUnaryCall();
        $dispatcher = new ScriptedGrpcDispatcher($call, new RecordingUnaryCall());
        $host = (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $resetter = new RecordingResetter();
        $kernel = self::kernel(static function (): never {
            throw new \RuntimeException('secret address');
        }, $resetter);

        Assert::same(self::runtime()->getRunner($kernel)->run(), 1);
        Assert::same($call->status?->code, StatusCode::Internal);
        Assert::same($call->status?->message, 'Internal server error.');
        Assert::same($dispatcher->receives, 1);
        Assert::same($resetter->resets, 1);
        Assert::same($kernel->events, ['boot', 'shutdown']);
        Assert::count($host->logs, 1);
    }

    public function failureAfterRespondingDoesNotWriteASecondResponse(): void
    {
        $call = new RecordingUnaryCall();
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($call)))->install();
        $kernel = self::kernel(static function (UnaryCall $call): never {
            $call->respond('OK');
            throw new \RuntimeException('Termination failed.');
        });

        Assert::same(self::runtime()->getRunner($kernel)->run(), 1);
        Assert::same($call->response, 'OK');
        Assert::null($call->status);
    }

    public function returningWithoutAnAnswerFailsTheCallAndStops(): void
    {
        $call = new RecordingUnaryCall();
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher($call)))->install();

        Assert::same(self::runtime()->getRunner(self::kernel(static function (): void {}))->run(), 1);
        Assert::same($call->status?->code, StatusCode::Internal);
    }

    public function resetFailureStopsBeforeTheNextCall(): void
    {
        $call = new RecordingUnaryCall();
        $dispatcher = new ScriptedGrpcDispatcher($call, new RecordingUnaryCall());
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $kernel = self::kernel(static fn(UnaryCall $call) => $call->respond('OK'), new RecordingResetter(
            static function (): never {
                throw new \RuntimeException('Reset failed.');
            },
        ));

        Assert::same(self::runtime()->getRunner($kernel)->run(), 1);
        Assert::same($dispatcher->receives, 1);
        Assert::same($call->response, 'OK');
        Assert::same($kernel->events, ['boot', 'shutdown']);
    }

    public function missingHandlerFailsBeforeTakingAnyCall(): void
    {
        $dispatcher = new ScriptedGrpcDispatcher(new RecordingUnaryCall());
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $kernel = new GrpcKernel(new Container());

        Assert::same(self::runtime()->getRunner($kernel)->run(), 1);
        Assert::same($dispatcher->receives, 0);
        Assert::same($kernel->events, ['boot', 'shutdown']);
    }

    public function aBareHttpKernelCannotProvideTheGrpcHandler(): never
    {
        (new FakeRuntime(Mode::Dispatcher, new ScriptedGrpcDispatcher()))->install();
        Expect::exception(\LogicException::class)->withMessage('Rapira gRPC requires a ' . KernelInterface::class . ' application.');

        self::runtime()->getRunner(new TestKernel());
    }

    public function releasesCallsBeforeBlockingAgain(): void
    {
        $call = new RecordingUnaryCall();
        $reference = \WeakReference::create($call);
        $dispatcher = new ScriptedGrpcDispatcher($call);
        unset($call);
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $dispatcher->beforeReceive = static function () use ($dispatcher, $reference): void {
            if ($dispatcher->receives === 2) {
                Assert::null($reference->get());
            }
        };

        Assert::same(self::runtime()->getRunner(self::kernel(static fn(UnaryCall $call) => $call->respond('OK')))->run(), 0);
    }

    public function releasesDiscardedCallsAndTheirExceptionTraceBeforeBlockingAgain(): void
    {
        $call = new RecordingUnaryCall(disconnectOnWrite: true);
        $reference = \WeakReference::create($call);
        $dispatcher = new ScriptedGrpcDispatcher($call);
        unset($call);
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        $dispatcher->beforeReceive = static function () use ($dispatcher, $reference): void {
            if ($dispatcher->receives === 2) {
                Assert::null($reference->get());
            }
        };
        $kernel = self::kernel(
            static fn(UnaryCall $call) => $call->respond('OK'),
            events: new CallbackEventDispatcher(static function (): void {}),
        );

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
    }

    private static function kernel(
        \Closure $handler,
        ?RecordingResetter $resetter = null,
        ?EventDispatcherInterface $events = null,
    ): GrpcKernel {
        $container = new Container();
        $container->set(UnaryCallHandlerInterface::class, new CallbackUnaryHandler($handler));
        if ($resetter !== null) {
            $container->set('services_resetter', $resetter);
        }
        if ($events !== null) {
            $container->set('event_dispatcher', $events);
        }

        return new GrpcKernel($container);
    }
}
