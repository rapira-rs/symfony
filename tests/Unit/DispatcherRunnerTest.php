<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Http\HttpDispatcherInfo;
use Rapira\Http\Request as RapiraRequest;
use Rapira\InetAddress;
use Rapira\Symfony\DispatcherRunner;
use Rapira\Symfony\Internal\DispatcherRequestFactory;
use Rapira\Symfony\Internal\ExchangeResponseEmitter;
use Rapira\Symfony\Tests\Support\StubExchange;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class DispatcherRunnerTest
{
    public function handlesSequentialFreshRequestsAndTerminatesBeforeReceivingNext(): void
    {
        $events = [];
        $dispatcher = new QueueHttpDispatcher([
            $this->exchange('/one'),
            $this->exchange('/two'),
        ], $events);
        $kernel = new LifecycleKernel($events);

        $result = $this->runner($kernel, $dispatcher)->run();

        Assert::same($result, 0);
        Assert::same($kernel->paths, ['/one', '/two']);
        Assert::false($kernel->requestIds[0] === $kernel->requestIds[1]);
        Assert::same($events, [
            'receive', 'handle:/one', 'terminate:/one',
            'receive', 'handle:/two', 'terminate:/two',
            'receive', 'closed',
        ]);
    }

    public function cancellationContinuesToNextExchangeWithoutTerminatingCancelledRequest(): void
    {
        $events = [];
        $cancelled = $this->exchange('/cancelled');
        $cancelled->cancelled = true;
        $healthy = $this->exchange('/healthy');
        $dispatcher = new QueueHttpDispatcher([$cancelled, $healthy], $events);
        $kernel = new LifecycleKernel($events);

        Assert::same($this->runner($kernel, $dispatcher)->run(), 0);
        Assert::same($kernel->paths, ['/cancelled', '/healthy']);
        Assert::same($kernel->terminated, ['/healthy']);
        Assert::same($cancelled->heads, []);
        Assert::true($healthy->finalized);
    }

    public function discardedWriteContinuesButUnexpectedKernelErrorsPropagate(): void
    {
        $events = [];
        $discarded = new DiscardingExchange($this->request('/discarded'));
        $healthy = $this->exchange('/healthy');
        $dispatcher = new QueueHttpDispatcher([$discarded, $healthy], $events);
        $kernel = new LifecycleKernel($events);
        Assert::same($this->runner($kernel, $dispatcher)->run(), 0);
        Assert::true($healthy->finalized);
        Assert::same($kernel->terminated, ['/healthy']);

        $events = [];
        $dispatcher = new QueueHttpDispatcher([$this->exchange('/fail'), $this->exchange('/must-not-run')], $events);
        $kernel = new ThrowingDispatcherKernel($events);
        Expect::exception(\RuntimeException::class)->withMessage('kernel failed');
        try {
            $this->runner($kernel, $dispatcher)->run();
        } finally {
            Assert::same($dispatcher->receiveCalls, 1);
        }
    }

    private function runner(HttpKernelInterface $kernel, HttpDispatcher $dispatcher): DispatcherRunner
    {
        return new DispatcherRunner(
            $kernel,
            $dispatcher,
            new DispatcherRequestFactory([]),
            new ExchangeResponseEmitter(),
        );
    }

    private function exchange(string $path): StubExchange
    {
        return new StubExchange($this->request($path));
    }

    private function request(string $path): RapiraRequest
    {
        return new RapiraRequest(
            'GET',
            'http://localhost' . $path,
            $path,
            'localhost',
            'HTTP/1.1',
            [],
            '',
            new InetAddress('127.0.0.1', 40000),
            new InetAddress('127.0.0.1', 8080),
            null,
            0.0,
        );
    }
}

final class QueueHttpDispatcher implements HttpDispatcher
{
    public int $receiveCalls = 0;

    /**
     * @param list<Exchange> $exchanges
     * @param list<string> $events
     */
    public function __construct(
        private array $exchanges,
        private array &$events,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function tryReceive(): ?Exchange
    {
        return \array_shift($this->exchanges);
    }

    public function receive(int $timeout = -1): Exchange
    {
        ++$this->receiveCalls;
        $this->events[] = 'receive';
        $exchange = \array_shift($this->exchanges);
        if ($exchange === null) {
            $this->events[] = 'closed';
            throw new ClosedException();
        }

        return $exchange;
    }

    public function getInfo(): HttpDispatcherInfo
    {
        throw new \BadMethodCallException();
    }
}

final class LifecycleKernel implements HttpKernelInterface, TerminableInterface
{
    /** @var list<string> */
    public array $paths = [];

    /** @var list<int> */
    public array $requestIds = [];

    /** @var list<string> */
    public array $terminated = [];

    /**
     * @param list<string> $events
     */
    public function __construct(private array &$events) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $path = $request->getPathInfo();
        $this->paths[] = $path;
        $this->requestIds[] = \spl_object_id($request);
        $this->events[] = 'handle:' . $path;

        return new Response($path);
    }

    public function terminate(Request $request, Response $response): void
    {
        $path = $request->getPathInfo();
        $this->terminated[] = $path;
        $this->events[] = 'terminate:' . $path;
    }
}

final class ThrowingDispatcherKernel implements HttpKernelInterface
{
    /**
     * @param list<string> $events
     */
    public function __construct(private array &$events) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->events[] = 'handle:' . $request->getPathInfo();
        throw new \RuntimeException('kernel failed');
    }
}

final class DiscardingExchange extends StubExchange
{
    public function writeHead(int $status, array $headers = []): void
    {
        throw new WorkDiscardedException();
    }
}
