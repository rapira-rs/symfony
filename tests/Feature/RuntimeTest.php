<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Runtime;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Test;

/**
 * What the Runtime does with the mode the host runs the process in, before any request is served.
 */
#[Test]
final class RuntimeTest
{
    use FakeRuntimeLifecycle;

    public function workerModeMarksTheProcessAsAWorkerForTheApplication(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);
        $runtime = (new FakeRuntime(Mode::Worker, captureOutput: true))->queue('GET', '/')->install();
        $kernel = new TestKernel();

        $runner = self::runtime()->getRunner($kernel);
        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
        $runner->run();

        Assert::same($runtime->outputs, ['/']);
        Assert::same($kernel->requests[0]->server->get('APP_RUNTIME_MODE'), 'web=1&worker=1');
    }

    public function dispatcherModeMarksTheProcessAsAWorkerForTheApplication(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);
        $exchange = FakeExchange::for('/');
        (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher($exchange)))->install();
        $kernel = new TestKernel();

        $runner = self::runtime()->getRunner($kernel);
        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
        $runner->run();

        Assert::same($exchange->getBody(), '/');
        Assert::same($kernel->requests[0]->server->get('APP_RUNTIME_MODE'), 'web=1&worker=1');
    }

    public function classicModeServesOneRequestFromTheSuperglobalsAndFinishesItBeforeTerminating(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);
        $host = (new FakeRuntime(Mode::Classic))->install();
        $kernel = new TestKernel(
            static fn(): Response => new StreamedResponse(static function (): void {
                echo 'one';
                \ob_flush();
                \ob_start();
                echo 'two';
            }),
            static function () use ($host, &$kernel): void {
                $kernel->events[] = 'finished:' . $host->finishedRequests;
            },
        );
        $runtime = self::runtime();
        Assert::false(isset($_SERVER['APP_RUNTIME_MODE']));

        [$result, $output, $levelChange] = self::serveClassic($runtime, $kernel);

        Assert::same($result, 0);
        Assert::same($output, 'onetwo');
        Assert::same($levelChange, 0);
        Assert::same($kernel->events, ['handle:/classic', 'terminate:/classic', 'finished:1']);
    }

    public function classicModeOutsideRapiraServesTheRequestAsWell(): void
    {
        // No double installed: the contract stubs answer as outside Rapira, `rapira_finish_request()` false.
        FakeRuntime::reset();
        $kernel = new TestKernel();

        [$result, $output] = self::serveClassic(self::runtime(), $kernel);

        Assert::same($result, 0);
        Assert::same($output, '/classic');
        Assert::same($kernel->events, ['handle:/classic', 'terminate:/classic']);
    }

    /**
     * Pins the current delegation to Symfony; whether a resident mode should instead keep such an
     * application alive is still open.
     */
    public function nonHttpApplicationRunsOnceInResidentModes(): void
    {
        foreach ([Mode::Worker, Mode::Dispatcher] as $mode) {
            (new FakeRuntime($mode, new FakeHttpDispatcher()))->install();

            Assert::same(self::runtime()->getRunner(static fn(): int => 17)->run(), 17);
        }
    }

    /**
     * Serves `GET /classic` from the superglobals and takes the output as it is flushed.
     *
     * @return array{int, string, int} The exit code, the output, and the change of the output buffer level.
     */
    private static function serveClassic(Runtime $runtime, TestKernel $kernel): array
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/classic',
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/index.php',
        ] + $_SERVER;
        $output = '';
        \ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        $level = \ob_get_level();
        try {
            $result = $runtime->getRunner($kernel)->run();
            $levelChange = \ob_get_level() - $level;
        } finally {
            while (\ob_get_level() >= $level) {
                \ob_end_flush();
            }
        }

        return [$result, $output, $levelChange];
    }
}
