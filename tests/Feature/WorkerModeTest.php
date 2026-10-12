<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\LogLevel;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\WorkerRequest;
use Rapira\Symfony\Tests\Support\BootRecordingKernel;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
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

    public function requestMetadataOfTheBootIsNotCarriedIntoRequests(): void
    {
        $_SERVER = [
            'HTTPS' => 'on',
            'HTTP_X_BOOT' => 'boot',
            'QUERY_STRING' => 'boot=1',
            'REMOTE_ADDR' => '192.0.2.1',
            'SCRIPT_FILENAME' => '/app/public/index.php',
        ] + $_SERVER;
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/plain');
        $kernel = new TestKernel();

        $this->run($runtime, $kernel);

        $request = $kernel->requests[0];
        Assert::false($request->isSecure());
        Assert::null($request->headers->get('x-boot'));
        Assert::null($request->getQueryString());
        Assert::same($request->getClientIp(), '127.0.0.1');
        Assert::same($request->server->get('SCRIPT_FILENAME'), '/app/public/index.php');
    }

    public function applicationEnvironmentVariablesSurviveRequestMetadataFiltering(): void
    {
        $settings = ['SERVER_ROLE' => 'api', 'REQUEST_TIMEOUT' => '30', 'AUTH_TOKEN' => 'token', 'CONTENT_DIR' => '/content', 'REMOTE_STORAGE' => 's3', 'PHP_AUTH_SETTING' => 'custom'];
        $_SERVER = $settings + $_SERVER;
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/env');
        $kernel = new TestKernel();

        Assert::same($this->run($runtime, $kernel), 0);

        foreach ($settings as $key => $value) {
            Assert::same($kernel->requests[0]->server->get($key), $value, $key);
        }
    }

    public function responseIsSentBeforeTheRequestTerminatesAndTheNextOneIsServed(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/one')
            ->queue('GET', '/two')
            ->queue('GET', '/three');
        $kernel = new TestKernel(terminate: static function () use ($runtime, &$kernel): void {
            $kernel->events[] = \sprintf('finished:%d current:%s body:%s', $runtime->finishedRequests, $_SERVER['REQUEST_URI'], \ob_get_contents());
        });

        $this->run($runtime, $kernel);

        Assert::same($kernel->events, [
            'handle:/one', 'terminate:/one', 'finished:1 current:/one body:/one',
            'handle:/two', 'terminate:/two', 'finished:2 current:/two body:/two',
            'handle:/three', 'terminate:/three', 'finished:3 current:/three body:/three',
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

    public function kernelBootsBeforeTheFirstRequest(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/one');
        $kernel = new BootRecordingKernel(new TestKernel(terminate: static function () use ($runtime, &$kernel): void {
            $kernel->inner->events[] = 'served:' . $runtime->servedRequests;
        }));

        $this->run($runtime, $kernel);

        Assert::same($kernel->inner->events, ['boot', 'handle:/one', 'terminate:/one', 'served:1']);
    }

    public function bootFailurePropagatesBeforeAnyRequestIsTaken(): never
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/must-not-run');
        $kernel = new BootRecordingKernel(new TestKernel(), new \RuntimeException('container broken'));

        Expect::exception(\RuntimeException::class)->withMessage('container broken');
        try {
            $this->run($runtime, $kernel);
        } finally {
            Assert::same($runtime->servedRequests, 0);
        }
    }

    public function kernelFailureIsAnsweredWithA500LoggedAndEndsTheLoop(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/failure')
            ->queue('GET', '/must-not-run');
        $failure = new \RuntimeException('kernel failed');
        $kernel = new TestKernel(static fn() => throw $failure);

        $result = $this->run($runtime, $kernel);

        Assert::same($result, 1);
        Assert::same($kernel->events, ['handle:/failure']);
        Assert::same($runtime->servedRequests, 1);
        Assert::same($runtime->finishedRequests, 1);
        Assert::same($runtime->outputs, ['Internal Server Error']);
        Assert::count($runtime->logs, 1);
        Assert::same($runtime->logs[0]['level'], LogLevel::Error);
        Assert::same($runtime->logs[0]['context']['exception'], $failure);
    }

    public function kernelFailureIsAnsweredByAnExtendingRuntime(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/failure');
        $kernel = new TestKernel(static fn() => throw new \RuntimeException('kernel failed'));

        $result = $this->run($runtime, $kernel, static fn(\Throwable $exception, Request $request): Response => new Response(
            \sprintf('%s at %s', $exception->getMessage(), $request->getPathInfo()),
        ));

        Assert::same($result, 1);
        Assert::same($runtime->outputs, ['kernel failed at /failure']);
    }

    public function failureResponseThatFailsFallsBackToA500(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/failure');
        $kernel = new TestKernel(static fn() => throw new \RuntimeException('kernel failed'));

        $result = $this->run($runtime, $kernel, static fn(): never => throw new \LogicException('page failed'));

        Assert::same($result, 1);
        Assert::same($runtime->outputs, ['Internal Server Error']);
        Assert::count($runtime->logs, 1);
    }

    public function terminateFailureIsLoggedAndEndsTheLoop(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))
            ->queue('GET', '/failure')
            ->queue('GET', '/must-not-run');
        $failure = new \RuntimeException('terminate failed');
        $kernel = new TestKernel(terminate: static fn() => throw $failure);

        $result = $this->run($runtime, $kernel);

        Assert::same($result, 1);
        Assert::same($kernel->events, ['handle:/failure', 'terminate:/failure']);
        Assert::same($runtime->outputs, ['/failure']);
        Assert::same($runtime->servedRequests, 1);
        Assert::count($runtime->logs, 1);
        Assert::same($runtime->logs[0]['context']['exception'], $failure);
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

    /**
     * @param null|\Closure(\Throwable, Request): Response $failurePage
     */
    private function run(FakeRuntime $runtime, HttpKernelInterface $kernel, ?\Closure $failurePage = null): int
    {
        $runtime->install();

        return self::runtime($failurePage)->getRunner($kernel)->run();
    }
}
