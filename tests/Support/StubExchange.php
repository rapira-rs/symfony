<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Http\Exchange;
use Rapira\Http\Request;

class StubExchange implements Exchange
{
    /** @var list<array{status: int, headers: array<non-empty-string, list<string>>}> */
    public array $heads = [];

    /** @var list<array{content: string, eos: bool}> */
    public array $bodies = [];

    public bool $cancelled = false;
    public bool $finalized = false;

    public function __construct(private readonly Request $request) {}

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function writeHead(int $status, array $headers = []): void
    {
        $this->heads[] = ['status' => $status, 'headers' => $headers];
    }

    public function writeBody(string $content, bool $eos = true): void
    {
        $this->bodies[] = ['content' => $content, 'eos' => $eos];
        $this->finalized = $eos;
    }

    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        throw new \BadMethodCallException();
    }

    public function writeTrailers(array $trailers): void
    {
        throw new \BadMethodCallException();
    }

    public function flush(): void {}

    public function __destruct() {}
}
