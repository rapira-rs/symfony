<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Stub;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class CallbackEventDispatcher implements EventDispatcherInterface
{
    public function __construct(private \Closure $listener) {}

    #[\Override]
    public function dispatch(object $event, ?string $eventName = null): object
    {
        ($this->listener)($event);

        return $event;
    }
}
