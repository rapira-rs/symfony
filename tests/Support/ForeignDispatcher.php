<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Dispatcher;
use Rapira\DispatcherInfo;
use Rapira\Work;

/**
 * The dispatcher of a plugin other than `http`, which hands out no HTTP exchanges.
 */
final class ForeignDispatcher implements Dispatcher
{
    #[\Override]
    public function name(): string
    {
        return 'jobs';
    }

    #[\Override]
    public function tryReceive(): ?Work
    {
        return null;
    }

    #[\Override]
    public function receive(int $timeout = -1): Work
    {
        throw new \LogicException('Not supported by the fixture.');
    }

    #[\Override]
    public function getInfo(): DispatcherInfo
    {
        throw new \LogicException('Not supported by the fixture.');
    }
}
