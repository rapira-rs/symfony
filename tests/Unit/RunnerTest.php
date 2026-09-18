<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Symfony\Internal\RequestLoop;
use Rapira\Symfony\Runner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class RunnerTest
{
    public function servesSequentialRequestsWithFreshGlobalsAndOneResidentKernel(): void
    {
        $_SERVER = [
            'APP_ENV' => 'test',
            'APP_SECRET' => 'boot-secret',
            'HTTP_BOOT_ONLY' => 'must-not-leak',
        ];
        $kernel = new RecordingKernel();
        $loop = new FakeRequestLoop([
            [
                'server' => [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/first?q=one',
                    'QUERY_STRING' => 'q=one',
                    'HTTP_HOST' => 'localhost',
                    'HTTP_X_REQUEST' => 'first',
                    'APP_ENV' => 'request-env',
                ],
                'get' => ['q' => 'one'],
                'post' => [],
                'cookie' => ['session' => 'first'],
            ],
            [
                'server' => [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/second?q=two',
                    'QUERY_STRING' => 'q=two',
                    'HTTP_HOST' => 'localhost',
                    'HTTP_X_REQUEST' => 'second',
                ],
                'get' => ['q' => 'two'],
                'post' => ['body' => 'second'],
                'cookie' => ['session' => 'second'],
            ],
        ]);

        \ob_start();
        $result = (new Runner($kernel, $loop))->run();
        $output = (string) \ob_get_clean();

        Assert::same($result, 0);
        Assert::same($output, 'response-1response-2');
        Assert::same($kernel->handled, [
            [
                'id' => $kernel->handled[0]['id'],
                'method' => 'GET',
                'path' => '/first',
                'query' => 'one',
                'body' => null,
                'cookie' => 'first',
                'header' => 'first',
                'app_env' => 'request-env',
                'secret' => 'boot-secret',
                'boot_header' => null,
            ],
            [
                'id' => $kernel->handled[1]['id'],
                'method' => 'POST',
                'path' => '/second',
                'query' => 'two',
                'body' => 'second',
                'cookie' => 'second',
                'header' => 'second',
                'app_env' => 'test',
                'secret' => 'boot-secret',
                'boot_header' => null,
            ],
        ]);
        Assert::false($kernel->handled[0]['id'] === $kernel->handled[1]['id']);
        Assert::same($loop->handlers, 2);
    }

    public function sendsThenTerminatesExactlyOncePerRequestAndDrains(): void
    {
        $_SERVER = ['APP_ENV' => 'test'];
        $events = new Events();
        $kernel = new TerminableRecordingKernel($events);
        $loop = new FakeRequestLoop([$this->requestGlobals('/lifecycle')], $events);

        \ob_start();
        $result = (new Runner($kernel, $loop))->run();
        \ob_end_clean();

        Assert::same($result, 0);
        Assert::same($events->values, ['loop:before', 'handle', 'send:true', 'loop:after', 'terminate', 'drain']);
        Assert::same($kernel->terminateCalls, 1);
    }

    public function terminateExceptionsPropagateBeforeCollectionAndTheNextRequest(): void
    {
        $_SERVER = ['APP_ENV' => 'test'];
        $events = new Events();
        $kernel = new TerminateThrowingKernel($events);
        $loop = new FakeRequestLoop([
            $this->requestGlobals('/failure'),
            $this->requestGlobals('/must-not-run'),
        ], $events);

        Expect::exception(\RuntimeException::class)->withMessage('terminate failed');
        try {
            (new Runner($kernel, $loop))->run();
        } finally {
            Assert::same($events->values, ['loop:before', 'handle', 'send:true', 'loop:after', 'terminate']);
            Assert::same($loop->handlers, 1);
        }
    }

    public function propagatesExceptionsWithoutTerminatingOrContinuing(): void
    {
        $_SERVER = ['APP_ENV' => 'test'];
        $events = new Events();
        $kernel = new ThrowingKernel($events);
        $loop = new FakeRequestLoop([
            $this->requestGlobals('/failure'),
            $this->requestGlobals('/must-not-run'),
        ], $events);

        Expect::exception(\RuntimeException::class)->withMessage('kernel failed');
        try {
            (new Runner($kernel, $loop))->run();
        } finally {
            Assert::same($events->values, ['loop:before', 'handle']);
            Assert::same($kernel->terminateCalls, 0);
            Assert::same($loop->handlers, 1);
        }
    }

    /**
     * @return array{server: array<string, string>, get: array{}, post: array{}, cookie: array{}}
     */
    private function requestGlobals(string $path): array
    {
        return [
            'server' => [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
                'HTTP_HOST' => 'localhost',
            ],
            'get' => [],
            'post' => [],
            'cookie' => [],
        ];
    }
}

final class FakeRequestLoop implements RequestLoop
{
    public int $handlers = 0;

    /**
     * @param list<array{server: array<string, string>, get: array<string, string>, post: array<string, string>, cookie: array<string, string>}> $requests
     */
    public function __construct(
        private array $requests,
        private readonly ?Events $events = null,
    ) {}

    public function handle(callable $handler): bool
    {
        $request = \array_shift($this->requests);
        if ($request === null) {
            if ($this->events !== null) {
                $this->events->values[] = 'drain';
            }

            return false;
        }

        $_SERVER = $request['server'];
        $_GET = $request['get'];
        $_POST = $request['post'];
        $_COOKIE = $request['cookie'];
        $_FILES = [];
        ++$this->handlers;
        if ($this->events !== null) {
            $this->events->values[] = 'loop:before';
        }
        $handler();
        if ($this->events !== null) {
            $this->events->values[] = 'loop:after';
        }

        return true;
    }
}

class RecordingKernel implements HttpKernelInterface
{
    /** @var list<array<string, int|string|null>> */
    public array $handled = [];

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->handled[] = [
            'id' => \spl_object_id($request),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->get('q'),
            'body' => $request->request->get('body'),
            'cookie' => $request->cookies->get('session'),
            'header' => $request->headers->get('x-request'),
            'app_env' => $request->server->get('APP_ENV'),
            'secret' => $request->server->get('APP_SECRET'),
            'boot_header' => $request->headers->get('boot-only'),
        ];

        return new Response('response-' . \count($this->handled));
    }
}

final class TerminableRecordingKernel implements HttpKernelInterface, TerminableInterface
{
    public int $terminateCalls = 0;

    public function __construct(private readonly Events $events) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->events->values[] = 'handle';

        return new EventResponse($this->events);
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminateCalls;
        $this->events->values[] = 'terminate';
    }
}

final class TerminateThrowingKernel implements HttpKernelInterface, TerminableInterface
{
    public function __construct(private readonly Events $events) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->events->values[] = 'handle';

        return new EventResponse($this->events);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->events->values[] = 'terminate';

        throw new \RuntimeException('terminate failed');
    }
}

final class ThrowingKernel implements HttpKernelInterface, TerminableInterface
{
    public int $terminateCalls = 0;

    public function __construct(private readonly Events $events) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->events->values[] = 'handle';

        throw new \RuntimeException('kernel failed');
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminateCalls;
    }
}

final class EventResponse extends Response
{
    public function __construct(private readonly Events $events)
    {
        parent::__construct();
    }

    public function send(bool $flush = true): static
    {
        $this->events->values[] = 'send:' . ($flush ? 'true' : 'false');

        return $this;
    }
}

final class Events
{
    /** @var list<string> */
    public array $values = [];
}
