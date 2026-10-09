<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Symfony\Runtime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;

/**
 * Builds the bridge's {@see Runtime} over an installed {@see FakeRuntime}, and puts the process back
 * as it was after each test: the double uninstalled, and the superglobals the Runtime and the test
 * wrote restored.
 */
trait FakeRuntimeLifecycle
{
    /** @var list<array<array-key, mixed>> */
    private array $processGlobals = [];

    #[BeforeTest]
    public function rememberProcessGlobals(): void
    {
        $this->processGlobals = [$_SERVER, $_ENV, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST];
    }

    #[AfterTest]
    public function resetRuntime(): void
    {
        FakeRuntime::reset();
        [$_SERVER, $_ENV, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST] = $this->processGlobals;
    }

    /**
     * The Runtime as `autoload_runtime.php` would build it, minus the process-wide error handler and the
     * debug-mode `umask(0)`, which would outlive the test.
     *
     * @param null|\Closure(\Throwable, Request): Response $failurePage Builds a {@see FailurePageRuntime} that
     *        answers a failure escaping the kernel with it.
     */
    private static function runtime(?\Closure $failurePage = null): Runtime
    {
        $options = ['debug' => false, 'error_handler' => false, 'env' => 'test'];

        return $failurePage === null
            ? new Runtime($options)
            : new FailurePageRuntime($options + ['failure_page' => $failurePage]);
    }
}
