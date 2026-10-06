<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Http\Multipart;
use Rapira\Http\UploadedFile;
use Rapira\LogLevel;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\ForeignDispatcher;
use Rapira\Symfony\Tests\Support\ScriptedExchange;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Expect;
use Testo\Skip;
use Testo\Test;

/**
 * The Runtime's receive loop in {@see Mode::Dispatcher}, with the host replaced by a scripted dispatcher.
 */
#[Test]
final class DispatcherModeTest
{
    use FakeRuntimeLifecycle;

    public function servesEveryExchangeAndTerminatesEachBeforeTheNextReceive(): void
    {
        $first = FakeExchange::for('/one');
        $second = FakeExchange::for('/two');
        $dispatcher = new FakeHttpDispatcher($first, $second);
        $kernel = new TestKernel();
        $dispatcher->beforeReceive = static function () use ($kernel): void {
            $kernel->events[] = 'receive';
        };

        $this->serve($kernel, $dispatcher);

        Assert::same($kernel->events, [
            'receive', 'handle:/one', 'terminate:/one',
            'receive', 'handle:/two', 'terminate:/two',
            'receive',
        ]);
        Assert::same($first->getBody(), '/one');
        Assert::same($second->getBody(), '/two');
        Assert::true($first->isFinalized());
        Assert::true($second->isFinalized());
        Assert::notSame($kernel->requests[0], $kernel->requests[1]);
    }

    #[Skip('rapira-rs/rapira#201: isCancelled() crashes Linux workers, so the bridge does not call it')]
    public function cancelledExchangeIsSkipped(): void
    {
        $cancelled = FakeExchange::for('/cancelled');
        $cancelled->discard();
        $healthy = FakeExchange::for('/healthy');
        $kernel = new TestKernel();

        $this->serve($kernel, new FakeHttpDispatcher($cancelled, $healthy));

        Assert::same($kernel->events, ['handle:/healthy', 'terminate:/healthy']);
        Assert::null($cancelled->status);
        Assert::true($healthy->isFinalized());
    }

    public function exchangeClosedByTheHostMidResponseStillTerminatesAndTheLoopGoesOn(): void
    {
        $gone = FakeExchange::for('/gone');
        $gone->discardOnWrite();
        $healthy = FakeExchange::for('/healthy');
        $kernel = new TestKernel();

        $this->serve($kernel, new FakeHttpDispatcher($gone, $healthy));

        Assert::same($kernel->events, ['handle:/gone', 'terminate:/gone', 'handle:/healthy', 'terminate:/healthy']);
        Assert::true($healthy->isFinalized());
    }

    public function discardRaisedByTheApplicationItselfPropagates(): never
    {
        $dispatcher = new FakeHttpDispatcher(FakeExchange::for('/terminate-fail'), FakeExchange::for('/must-not-run'));
        $kernel = new TestKernel(
            terminate: static fn() => throw new WorkDiscardedException('application terminate discard'),
        );

        Expect::exception(WorkDiscardedException::class)->withMessage('application terminate discard');
        try {
            $this->serve($kernel, $dispatcher);
        } finally {
            Assert::same($dispatcher->receives, 1);
        }
    }

    public function kernelFailurePropagatesWithoutServingTheNextExchange(): never
    {
        $next = FakeExchange::for('/must-not-run');
        $dispatcher = new FakeHttpDispatcher(FakeExchange::for('/fail'), $next);
        $kernel = new TestKernel(static fn() => throw new \RuntimeException('kernel failed'));

        Expect::exception(\RuntimeException::class)->withMessage('kernel failed');
        try {
            $this->serve($kernel, $dispatcher);
        } finally {
            Assert::same($dispatcher->receives, 1);
            Assert::null($next->status);
        }
    }

    public function exchangeIsReleasedBeforeTheNextReceive(): void
    {
        $exchange = FakeExchange::for('/');
        $reference = \WeakReference::create($exchange);
        $dispatcher = new FakeHttpDispatcher($exchange);
        unset($exchange);
        $released = null;
        $dispatcher->beforeReceive = static function () use ($dispatcher, $reference, &$released): void {
            $dispatcher->receives === 2 and $released = $reference->get() === null;
        };

        $this->serve(new TestKernel(), $dispatcher);

        Assert::true($released);
    }

    public function refusesADispatcherOfAnotherPlugin(): never
    {
        (new FakeRuntime(Mode::Dispatcher, new ForeignDispatcher()))->install();

        Expect::exception(\LogicException::class)
            ->withMessage('Rapira Dispatcher mode requires an HTTP dispatcher; got ' . ForeignDispatcher::class . '.');

        self::runtime()->getRunner(new TestKernel());
    }

    /**
     * Holds whether the loop survives the rejection or not.
     */
    public function terminalInterimStatusNeverReachesTheClient(): void
    {
        $exchange = new ScriptedExchange(FakeExchange::for('/'));
        $kernel = new TestKernel(static fn(): Response => new Response('', 103));

        try {
            $this->serve($kernel, new FakeHttpDispatcher($exchange));
        } catch (\LogicException) {
        }

        Assert::false(\in_array(103, $exchange->heads, true));
    }

    /**
     * Holds whether the loop survives the rejection or not.
     */
    public function eventStreamResponseIsNeverStreamed(): void
    {
        $exchange = new ScriptedExchange(FakeExchange::for('/'));
        $kernel = new TestKernel(
            static fn(): Response => new EventStreamResponse(static fn(): iterable => yield 'event'),
        );

        try {
            $this->serve($kernel, new FakeHttpDispatcher($exchange));
        } catch (\LogicException) {
        }

        Assert::false(\in_array(200, $exchange->heads, true));
        Assert::string($exchange->exchange->getBody())->notContains('event');
    }

    public function requestConversionFailureIsAnsweredWithAnErrorAndTheLoopGoesOn(): void
    {
        $missing = \sys_get_temp_dir() . '/rapira-missing-' . \bin2hex(\random_bytes(8));
        $failing = FakeExchange::for('/upload', 'POST', body: new Multipart([], [
            new UploadedFile('file', 'named.txt', 'text/plain', [], $missing, 10),
        ]));

        $this->assertAnsweredWith500AndTheNextServed($failing, $failing, new TestKernel());
    }

    public function eventStreamResponseIsAnsweredWithAnErrorAndTheLoopGoesOn(): void
    {
        $failing = FakeExchange::for('/events');
        $kernel = new TestKernel(static fn(Request $request): Response => $request->getPathInfo() === '/events'
            ? new EventStreamResponse(static fn(): iterable => yield 'event')
            : new Response($request->getPathInfo()));

        $this->assertAnsweredWith500AndTheNextServed($failing, $failing, $kernel);
    }

    public function terminalInterimStatusIsAnsweredWithAnErrorAndTheLoopGoesOn(): void
    {
        $failing = FakeExchange::for('/early');
        $kernel = new TestKernel(static fn(Request $request): Response => $request->getPathInfo() === '/early'
            ? new Response('', 103)
            : new Response($request->getPathInfo()));

        $this->assertAnsweredWith500AndTheNextServed($failing, $failing, $kernel);
    }

    public function headerValueTheHostRejectsIsAnsweredWithAnErrorAndTheLoopGoesOn(): void
    {
        $failing = new ScriptedExchange(FakeExchange::for('/bad-header'));
        $kernel = new TestKernel(static fn(Request $request): Response => $request->getPathInfo() === '/bad-header'
            ? new Response('body', 200, ['X-Bad' => "one\r\ntwo"])
            : new Response($request->getPathInfo()));

        $this->assertAnsweredWith500AndTheNextServed($failing, $failing->exchange, $kernel);
    }

    public function streamFailingBeforeItPrintsIsAnsweredWithAnErrorAndStillTerminated(): void
    {
        $failing = FakeExchange::for('/stream');
        $kernel = new TestKernel(static fn(Request $request): Response => $request->getPathInfo() === '/stream'
            ? new StreamedResponse(static fn() => throw new \RuntimeException('stream failed'))
            : new Response($request->getPathInfo()));

        $this->assertAnsweredWith500AndTheNextServed($failing, $failing, $kernel);

        Assert::same($kernel->events, ['handle:/stream', 'terminate:/stream', 'handle:/next', 'terminate:/next']);
    }

    public function streamFailingAfterItsHeadIsOutPropagates(): never
    {
        $failing = FakeExchange::for('/stream');
        $kernel = new TestKernel(static fn(): Response => new StreamedResponse(static function (): void {
            echo 'partial';
            \ob_flush();

            throw new \RuntimeException('stream failed');
        }));

        Expect::exception(\RuntimeException::class)->withMessage('stream failed');
        try {
            $this->serve($kernel, new FakeHttpDispatcher($failing, FakeExchange::for('/must-not-run')));
        } finally {
            Assert::same($failing->getBody(), 'partial');
            Assert::false($failing->isFinalized());
        }
    }

    /**
     * @param FakeExchange $answer What $failing records the answer in.
     */
    private function assertAnsweredWith500AndTheNextServed(Exchange $failing, FakeExchange $answer, TestKernel $kernel): void
    {
        $next = FakeExchange::for('/next');

        $host = $this->serve($kernel, new FakeHttpDispatcher($failing, $next));

        Assert::same($answer->status, 500);
        Assert::true($answer->isFinalized());
        Assert::same($next->getBody(), '/next');
        Assert::count($host->logs, 1);
        Assert::same($host->logs[0]['level'], LogLevel::Error);
        Assert::instanceOf($host->logs[0]['context']['exception'], \Throwable::class);
    }

    private function serve(TestKernel $kernel, FakeHttpDispatcher $dispatcher): FakeRuntime
    {
        $host = (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);

        return $host;
    }
}
