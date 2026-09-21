<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Http\HttpDispatcherInfo;

final class StubHttpDispatcher implements HttpDispatcher
{
    public function name(): string
    {
        return 'http';
    }

    public function tryReceive(): ?Exchange
    {
        return null;
    }

    public function receive(int $timeout = -1): Exchange
    {
        throw new ClosedException();
    }

    public function getInfo(): HttpDispatcherInfo
    {
        throw new \BadMethodCallException();
    }
}
