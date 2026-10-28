<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Stub;

use Rapira\Grpc\UnaryCall;
use Rapira\Symfony\Grpc\UnaryCallHandlerInterface;

final readonly class CallbackUnaryHandler implements UnaryCallHandlerInterface
{
    public function __construct(private \Closure $handler) {}

    #[\Override]
    public function handle(UnaryCall $call): void
    {
        ($this->handler)($call);
    }
}
