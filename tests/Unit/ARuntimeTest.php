<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Mode;
use Rapira\Symfony\DispatcherRunner;
use Rapira\Symfony\Tests\Support\NullKernel;
use Rapira\Symfony\Tests\Support\RuntimeForMode;
use Rapira\Symfony\Tests\Support\StubHttpDispatcher;
use Rapira\Symfony\WorkerRunner;
use Symfony\Component\Runtime\Runner\Symfony\HttpKernelRunner;
use Testo\Assert;
use Testo\Test;

#[Test]
final class RuntimeTest
{
    public function workerModeSelectsWorkerRunnerAndSetsRuntimeModeBeforeSymfonyInitialization(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        $runtime = new RuntimeForMode(Mode::Worker, ['debug' => false, 'error_handler' => false]);

        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
        Assert::true($runtime->getRunner(new NullKernel()) instanceof WorkerRunner);
    }

    public function dispatcherModeSelectsDispatcherRunnerAndSetsRuntimeModeBeforeSymfonyInitialization(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        $runtime = new RuntimeForMode(
            Mode::Dispatcher,
            ['debug' => false, 'error_handler' => false],
            new StubHttpDispatcher(),
        );

        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
        Assert::true($runtime->getRunner(new NullKernel()) instanceof DispatcherRunner);
    }

    public function classicModeDelegatesToSymfonyRuntime(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        $runtime = new RuntimeForMode(Mode::Classic, ['debug' => false, 'error_handler' => false]);

        Assert::false(isset($_SERVER['APP_RUNTIME_MODE']));
        Assert::true($runtime->getRunner(new NullKernel()) instanceof HttpKernelRunner);
    }

    public function nonHttpApplicationsDelegateInResidentModes(): void
    {
        foreach ([Mode::Worker, Mode::Dispatcher] as $mode) {
            $runtime = new RuntimeForMode($mode, ['debug' => false, 'error_handler' => false]);
            Assert::same($runtime->getRunner(static fn(): int => 17)->run(), 17);
        }
    }
}
