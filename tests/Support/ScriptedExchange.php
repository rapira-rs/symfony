<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Http\Exchange;
use Rapira\Http\Request;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;

/**
 * A {@see FakeExchange} that also rejects header values the host cannot put on the wire, records every
 * head written, interim ones included, and can fail its body writes.
 */
final class ScriptedExchange implements Exchange
{
    /** @var list<int> Statuses of every accepted {@see writeHead()}, interim ones included. */
    public array $heads = [];

    /** Thrown by every {@see writeBody()} once set. */
    public ?\Throwable $bodyFailure = null;

    public function __construct(
        public readonly FakeExchange $exchange,
    ) {}

    #[\Override]
    public function getRequest(): Request
    {
        return $this->exchange->getRequest();
    }

    #[\Override]
    public function writeHead(int $status, array $headers = []): void
    {
        // The host refuses what HTTP/1.1 field syntax cannot carry; FakeExchange takes anything.
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                \preg_match('/[\r\n\0]/', $value) === 1
                    and throw new \ValueError(\sprintf('Header "%s" has a value not representable on the wire.', $name));
            }
        }

        $this->exchange->writeHead($status, $headers);
        $this->heads[] = $status;
    }

    #[\Override]
    public function writeBody(string $content, bool $eos = true): void
    {
        $this->bodyFailure === null or throw $this->bodyFailure;

        $this->exchange->writeBody($content, $eos);
    }

    #[\Override]
    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        $this->exchange->sendFile($path, $offset, $length, $eos);
    }

    #[\Override]
    public function writeTrailers(array $trailers): void
    {
        $this->exchange->writeTrailers($trailers);
    }

    #[\Override]
    public function flush(): void
    {
        $this->exchange->flush();
    }

    #[\Override]
    public function isFinalized(): bool
    {
        return $this->exchange->isFinalized();
    }

    #[\Override]
    public function isCancelled(): bool
    {
        return $this->exchange->isCancelled();
    }
}
