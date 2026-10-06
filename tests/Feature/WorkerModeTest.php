<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\WorkerRequest;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * The Runtime in {@see Mode::Worker}, with the host replaced by a runtime that fills the superglobals
 * per request.
 */
#[Test]
final class WorkerModeTest
{
    use FakeRuntimeLifecycle;

    public function servesEveryRequestWithFreshGlobalsAndOneResidentKernel(): void
    {
        $_SERVER['APP_SECRET'] = 'boot-secret';
        $runtime = new FakeRuntime(Mode::Worker, captureOutput: true, requests: [
            WorkerRequest::create(
                'GET',
                '/first?q=one',
                ['HTTP_X_REQUEST' => 'first', 'APP_ENV' => 'request-env'],
                cookies: ['session' => 'first'],
            ),
            WorkerRequest::create(
                'POST',
                '/second?q=two',
                ['HTTP_X_REQUEST' => 'second'],
                cookies: ['session' => 'second'],
                post: ['body' => 'second'],
            ),
        ]);
        $kernel = new TestKernel(static fn(Request $request): Response => new Response('response-' . $request->query->get('q')));

        $result = $this->run($runtime, $kernel);

        Assert::same($result, 0);
        Assert::same($runtime->outputs, ['response-one', 'response-two']);
        Assert::same(\array_map(self::describe(...), $kernel->requests), [
            [
                'method' => 'GET',
                'path' => '/first',
                'query' => 'one',
                'body' => null,
                'cookie' => 'first',
                'header' => 'first',
                'app_env' => 'request-env',
                'secret' => 'boot-secret',
            ],
            [
                'method' => 'POST',
                'path' => '/second',
                'query' => 'two',
                'body' => 'second',
                'cookie' => 'second',
                'header' => 'second',
                'app_env' => 'test',
                'secret' => 'boot-secret',
            ],
        ]);
        Assert::notSame($kernel->requests[0], $kernel->requests[1]);
    }

    public function responseIsSentBeforeTheRequestTerminatesAndTheNextOneIsServed(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/one')
            ->queue('GET', '/two')
            ->queue('GET', '/three');
        $kernel = new TestKernel(terminate: static function () use ($runtime, &$kernel): void {
            $kernel->events[] = \sprintf('sent:%d served:%d', \count($runtime->outputs), $runtime->servedRequests);
        });

        $this->run($runtime, $kernel);

        Assert::same($kernel->events, [
            'handle:/one', 'terminate:/one', 'sent:1 served:1',
            'handle:/two', 'terminate:/two', 'sent:2 served:2',
            'handle:/three', 'terminate:/three', 'sent:3 served:3',
        ]);
        Assert::same($runtime->outputs, ['/one', '/two', '/three']);
    }

    public function responseIsFinishedBeforeTheRequestTerminates(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/one')
            ->queue('GET', '/two');
        $kernel = new TestKernel(terminate: static function () use ($runtime, &$kernel): void {
            $kernel->events[] = 'finished:' . $runtime->finishedRequests;
        });

        $this->run($runtime, $kernel);

        Assert::same($kernel->events, [
            'handle:/one', 'terminate:/one', 'finished:1',
            'handle:/two', 'terminate:/two', 'finished:2',
        ]);
    }

    public function flushingStreamedResponseArrivesWholeAndLeavesNoBufferBehind(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/stream');
        $kernel = new TestKernel(static fn(): Response => new StreamedResponse(static function (): void {
            echo 'one';
            \ob_flush();
            \ob_start();
            echo 'two';
        }));
        $level = \ob_get_level();

        $this->run($runtime, $kernel);

        Assert::same($runtime->outputs, ['onetwo']);
        Assert::same(\ob_get_level(), $level);
    }

    public function requestServedByTheLastHandleRequestCallIsTerminated(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/one')
            ->queue('GET', '/last');
        $kernel = new TestKernel();

        $this->run($runtime, $kernel);

        Assert::same($kernel->events, ['handle:/one', 'terminate:/one', 'handle:/last', 'terminate:/last']);
    }

    public function terminateFailurePropagatesBeforeTheNextRequest(): never
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/failure')
            ->queue('GET', '/must-not-run');
        $kernel = new TestKernel(terminate: static fn() => throw new \RuntimeException('terminate failed'));

        Expect::exception(\RuntimeException::class)->withMessage('terminate failed');
        try {
            $this->run($runtime, $kernel);
        } finally {
            Assert::same($kernel->events, ['handle:/failure', 'terminate:/failure']);
            Assert::same($runtime->servedRequests, 1);
        }
    }

    public function kernelFailurePropagatesWithoutTerminatingOrContinuing(): never
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/failure')
            ->queue('GET', '/must-not-run');
        $kernel = new TestKernel(static fn() => throw new \RuntimeException('kernel failed'));

        Expect::exception(\RuntimeException::class)->withMessage('kernel failed');
        try {
            $this->run($runtime, $kernel);
        } finally {
            Assert::same($kernel->events, ['handle:/failure']);
            Assert::same($runtime->servedRequests, 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->get('q'),
            'body' => $request->request->get('body'),
            'cookie' => $request->cookies->get('session'),
            'header' => $request->headers->get('x-request'),
            'app_env' => $request->server->get('APP_ENV'),
            'secret' => $request->server->get('APP_SECRET'),
        ];
    }

    private function run(FakeRuntime $runtime, TestKernel $kernel): int
    {
        $runtime->install();

        return self::runtime()->getRunner($kernel)->run();
    }
}
