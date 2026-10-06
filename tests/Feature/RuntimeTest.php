<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\TestKernel;
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

    public function classicModeServesOneRequestFromTheSuperglobalsThroughSymfony(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);
        (new FakeRuntime(Mode::Classic))->install();
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/classic',
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/index.php',
        ] + $_SERVER;
        $kernel = new TestKernel();
        $runtime = self::runtime();
        Assert::false(isset($_SERVER['APP_RUNTIME_MODE']));

        $output = '';
        // Symfony's runner closes every output buffer it may remove, so the output is taken as it is flushed.
        \ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        $level = \ob_get_level();
        try {
            $result = $runtime->getRunner($kernel)->run();
        } finally {
            \ob_get_level() === $level and \ob_end_flush();
        }

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
}
