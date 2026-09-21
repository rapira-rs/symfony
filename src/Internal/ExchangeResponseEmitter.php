<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
final readonly class ExchangeResponseEmitter
{
    public function emit(Exchange $exchange, Request $request, Response $response): void
    {
        if ($response->getStatusCode() >= 100 && $response->getStatusCode() < 200 && $response->getStatusCode() !== 101) {
            throw new \LogicException('A terminal Symfony response cannot use an interim HTTP status.');
        }

        if (\class_exists(EventStreamResponse::class) && $response instanceof EventStreamResponse) {
            throw new \LogicException('Symfony EventStreamResponse is not supported in Rapira Dispatcher mode.');
        }

        $rawMethod = $request->attributes->getString('rapira.request_method');
        if ($rawMethod === '') {
            $rawMethod = $request->server->getString('RAPIRA_REQUEST_METHOD', $request->server->getString('REQUEST_METHOD'));
        }
        $prepareRequest = $request;
        if ($rawMethod === 'HEAD') {
            $prepareRequest = clone $request;
            $prepareRequest->setMethod('GET');
        } elseif ($request->isMethod('HEAD')) {
            $prepareRequest = clone $request;
            $prepareRequest->setMethod('RAPIRA_NONSTANDARD_HEAD');
        }

        $response->prepare($prepareRequest);
        if ($response instanceof BinaryFileResponse && !$response->isSuccessful()) {
            $response->headers->remove('Content-Length');
        }

        $this->checkCancellation($exchange);
        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $this->writeHead($exchange, $status, $this->headers($response));

        if ($rawMethod === 'HEAD' || $response->isEmpty()) {
            if ($response instanceof BinaryFileResponse) {
                $this->sendContent($exchange, $response, false);
            }

            $this->checkCancellation($exchange);
            $this->writeBody($exchange, '');

            return;
        }

        $content = $response->getContent();
        if ($content !== false) {
            $this->checkCancellation($exchange);
            $this->writeBody($exchange, $content);

            return;
        }

        $this->sendContent($exchange, $response, true);
        $this->checkCancellation($exchange);
        $this->writeBody($exchange, '');
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

    private function sendContent(Exchange $exchange, Response $response, bool $forward): void
    {
        $baseLevel = \ob_get_level();
        $failure = null;

        \ob_start(function (string $chunk) use ($exchange, $forward, &$failure): string {
            if ($failure !== null || !$forward || $chunk === '') {
                return '';
            }

            try {
                $this->checkCancellation($exchange);
                $this->writeBody($exchange, $chunk, false);
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            return '';
        }, 1);
        $ownedLevel = $baseLevel + 1;

        try {
            $response->sendContent();
            $this->closeBuffersAbove($ownedLevel, true);
            if (\ob_get_level() !== $ownedLevel) {
                throw new \LogicException('The response removed the Dispatcher output buffer.');
            }
            if (!@\ob_end_flush()) {
                throw new \LogicException('The Dispatcher output buffer could not be flushed.');
            }
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        } finally {
            try {
                $this->restoreBufferLevel($baseLevel);
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function closeBuffersAbove(int $targetLevel, bool $flush): void
    {
        while (\ob_get_level() > $targetLevel) {
            $level = \ob_get_level();
            $closed = $flush ? @\ob_end_flush() : @\ob_end_clean();
            if (!$closed || \ob_get_level() >= $level) {
                throw new \LogicException('A nested output buffer cannot be removed safely.');
            }
        }
    }

    private function restoreBufferLevel(int $targetLevel): void
    {
        while (\ob_get_level() > $targetLevel) {
            $level = \ob_get_level();
            if (!@\ob_end_clean() || \ob_get_level() >= $level) {
                throw new \LogicException('The output buffer stack cannot be restored safely.');
            }
        }

        if (\ob_get_level() < $targetLevel) {
            throw new \LogicException('The response removed an output buffer it does not own.');
        }
    }

    /**
     * @param int<100, 599> $status
     * @param array<non-empty-string, list<string>> $headers
     */
    private function writeHead(Exchange $exchange, int $status, array $headers): void
    {
        try {
            $exchange->writeHead($status, $headers);
        } catch (WorkDiscardedException $exception) {
            throw new ResponseDiscardedException($exception);
        }
    }

    private function writeBody(Exchange $exchange, string $content, bool $eos = true): void
    {
        try {
            $exchange->writeBody($content, $eos);
        } catch (WorkDiscardedException $exception) {
            throw new ResponseDiscardedException($exception);
        }
    }

    private function checkCancellation(Exchange $exchange): void
    {
        if ($exchange->isCancelled()) {
            throw new ResponseDiscardedException();
        }
    }
}
