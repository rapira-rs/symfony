<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Mode;
use Rapira\Symfony\Runner;
use Rapira\Symfony\Tests\Support\NullKernel;
use Rapira\Symfony\Tests\Support\RuntimeForMode;
use Symfony\Component\Runtime\Runner\Symfony\HttpKernelRunner;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class RuntimeTest
{
    public function workerModeSelectsRapiraRunnerAndSetsRuntimeModeBeforeSymfonyInitialization(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        $runtime = new RuntimeForMode(Mode::Worker, ['debug' => false, 'error_handler' => false]);

        Assert::same($_SERVER['APP_RUNTIME_MODE'], 'web=1&worker=1');
        Assert::true($runtime->getRunner(new NullKernel()) instanceof Runner);
    }

    public function classicModeDelegatesToSymfonyRuntime(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        $runtime = new RuntimeForMode(Mode::Classic, ['debug' => false, 'error_handler' => false]);

        Assert::false(isset($_SERVER['APP_RUNTIME_MODE']));
        Assert::true($runtime->getRunner(new NullKernel()) instanceof HttpKernelRunner);
    }

    public function nonHttpApplicationsDelegateInWorkerMode(): void
    {
        $runtime = new RuntimeForMode(Mode::Worker, ['debug' => false, 'error_handler' => false]);

        Assert::same($runtime->getRunner(static fn(): int => 17)->run(), 17);
    }

    public function dispatcherModeRejectsHttpKernelApplications(): void
    {
        $runtime = new RuntimeForMode(Mode::Dispatcher, ['debug' => false, 'error_handler' => false]);

        Expect::exception(\LogicException::class)
            ->withMessage('Rapira Dispatcher mode is not supported for Symfony HttpKernel applications.');
        $runtime->getRunner(new NullKernel());
    }
}
