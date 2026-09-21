<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
final readonly class ExchangeResponseEmitter
{
    public function emit(Exchange $exchange, Request $request, Response $response): void
    {
        $response->prepare($request);
        if (
            $request->isMethod('HEAD')
            || $response->isInformational()
            || $response->isEmpty()
            || ($response->getContent() === false && !$response->isSuccessful())
        ) {
            $response->headers->remove('Content-Length');
        }

        $this->checkCancellation($exchange);
        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $exchange->writeHead($status, $this->headers($response));

        if ($request->isMethod('HEAD') || $response->isInformational() || $response->isEmpty()) {
            $this->checkCancellation($exchange);
            $exchange->writeBody('');

            return;
        }

        $content = $response->getContent();
        if ($content !== false) {
            $this->checkCancellation($exchange);
            $exchange->writeBody($content);

            return;
        }

        $this->stream($exchange, $response);
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private function headers(Response $response): array
    {
        /** @var array<non-empty-string, list<string>> $headers */
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        $cookies = $response->headers->getCookies();
        if ($cookies !== []) {
            $headers['Set-Cookie'] = \array_values(\array_map(
                static fn(object $cookie): string => (string) $cookie,
                $cookies,
            ));
        }

        return $headers;
    }

    private function stream(Exchange $exchange, Response $response): void
    {
        $level = \ob_get_level();
        $failure = null;

        \ob_start(function (string $chunk) use ($exchange): string {
            if ($chunk !== '') {
                $this->checkCancellation($exchange);
                $exchange->writeBody($chunk, false);
            }

            return '';
        }, 1);

        try {
            $response->sendContent();
            if (\ob_get_level() <= $level) {
                throw new \LogicException('The response removed the Dispatcher output buffer.');
            }

            while (\ob_get_level() > $level + 1) {
                \ob_end_flush();
            }
            \ob_end_flush();
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        $this->checkCancellation($exchange);
        $exchange->writeBody('');
    }

    private function checkCancellation(Exchange $exchange): void
    {
        if ($exchange->isCancelled()) {
            throw new WorkDiscardedException('The HTTP exchange was cancelled.');
        }
    }
}
