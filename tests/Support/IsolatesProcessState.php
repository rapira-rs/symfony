<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;

trait IsolatesProcessState
{
    /** @var array{server: array, env: array, get: array, post: array, cookie: array, files: array, request: array} */
    private array $superglobals;

    private int $ignoreUserAbort;
    private int $outputBufferLevel;

    #[BeforeTest]
    public function snapshotProcessState(): void
    {
        $this->superglobals = [
            'server' => $_SERVER,
            'env' => $_ENV,
            'get' => $_GET,
            'post' => $_POST,
            'cookie' => $_COOKIE,
            'files' => $_FILES,
            'request' => $_REQUEST,
        ];
        $this->ignoreUserAbort = \ignore_user_abort();
        $this->outputBufferLevel = \ob_get_level();
    }

    #[AfterTest]
    public function restoreProcessState(): void
    {
        try {
            $this->restoreOutputBufferLevel($this->outputBufferLevel);
        } finally {
            $_SERVER = $this->superglobals['server'];
            $_ENV = $this->superglobals['env'];
            $_GET = $this->superglobals['get'];
            $_POST = $this->superglobals['post'];
            $_COOKIE = $this->superglobals['cookie'];
            $_FILES = $this->superglobals['files'];
            $_REQUEST = $this->superglobals['request'];
            \ignore_user_abort((bool) $this->ignoreUserAbort);
        }
    }

    private function restoreOutputBufferLevel(int $level): void
    {
        while (\ob_get_level() > $level) {
            if (!@\ob_end_clean()) {
                throw new \RuntimeException('Unable to restore the output buffer level.');
            }
        }
    }
}
