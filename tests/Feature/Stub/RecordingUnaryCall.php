<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Stub;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Call\Context;
use Rapira\Grpc\Responder\ResponseMetadata;
use Rapira\Grpc\Status;
use Rapira\Grpc\UnaryCall;

final class RecordingUnaryCall implements UnaryCall
{
    public ?string $response = null;
    public ?Status $status = null;

    public function __construct(
        public readonly string $message = 'request',
        public bool $cancelled = false,
        public readonly bool $disconnectOnWrite = false,
    ) {}

    #[\Override]
    public function getMessage(): string
    {
        return $this->message;
    }

    #[\Override]
    public function getContext(): Context
    {
        throw new \LogicException('The runtime does not inspect application metadata.');
    }

    #[\Override]
    public function getResponseMetadata(): ResponseMetadata
    {
        throw new \LogicException('The runtime does not modify application metadata.');
    }

    #[\Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    #[\Override]
    public function isFinalized(): bool
    {
        return $this->cancelled || $this->response !== null || $this->status !== null;
    }

    #[\Override]
    public function respond(string $message): void
    {
        $this->beforeWrite();
        $this->response = $message;
    }

    #[\Override]
    public function fail(Status $status): void
    {
        $this->beforeWrite();
        $this->status = $status;
    }

    private function beforeWrite(): void
    {
        if ($this->disconnectOnWrite || $this->cancelled) {
            $this->cancelled = true;
            throw new WorkDiscardedException('Client left.');
        }
        if ($this->isFinalized()) {
            throw new AlreadyFinalizedError('Already answered.');
        }
    }
}
