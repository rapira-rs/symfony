<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Http\Exchange;
use Rapira\Http\Request;

/**
 * @internal
 */
final readonly class RequestExchange implements Exchange
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private Exchange $exchange,
        private Request $request,
    ) {}

    #[\Override]
    public function getRequest(): Request
    {
        return $this->request;
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

    #[\Override]
    public function writeHead(int $status, array $headers = []): void
    {
        $this->exchange->writeHead($status, $headers);
    }

    #[\Override]
    public function writeBody(string $content, bool $eos = true): void
    {
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

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function __destruct() {}
}
