<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Stub;

use Rapira\Exception\ClosedException;
use Rapira\Grpc\GrpcDispatcher;
use Rapira\Grpc\GrpcDispatcherInfo;
use Rapira\Grpc\UnaryCall;

final class ScriptedGrpcDispatcher implements GrpcDispatcher
{
    public int $receives = 0;
    public ?\Closure $beforeReceive = null;
    private array $calls;

    public function __construct(UnaryCall|\Throwable ...$calls)
    {
        $this->calls = \array_values($calls);
    }

    #[\Override]
    public function name(): string
    {
        return 'grpc';
    }

    #[\Override]
    public function receive(int $timeout = -1): UnaryCall
    {
        ++$this->receives;
        ($this->beforeReceive)?->__invoke();
        $call = \array_shift($this->calls) ?? new ClosedException('Drained.');
        if ($call instanceof \Throwable) {
            throw $call;
        }

        return $call;
    }

    #[\Override]
    public function tryReceive(): ?UnaryCall
    {
        throw new \LogicException('Not used by the runner.');
    }

    #[\Override]
    public function getInfo(): GrpcDispatcherInfo
    {
        throw new \LogicException('Not used by the runner.');
    }

    #[\Override]
    public function getServices(): array
    {
        return [];
    }
}
