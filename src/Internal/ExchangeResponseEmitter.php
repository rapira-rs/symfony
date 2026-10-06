<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a Symfony response into one exchange: the Dispatcher-mode counterpart of {@see Response::send()}.
 *
 * A buffered body goes out with the head. A file of a {@see BinaryFileResponse} is handed to the host with
 * {@see Exchange::sendFile()}, so PHP never holds its bytes. Anything else that prints its body is
 * captured from the output layer and forwarded in chunks, the head going out with the first one, so a
 * body that fails before printing anything can still be answered with an error.
 *
 * @internal
 */
final class ExchangeResponseEmitter
{
    /**
     * Bytes of printed output held before they are forwarded. An explicit `ob_flush()` forwards at once; a
     * bare `flush()` reaches no output handler, so it cannot.
     */
    private const CHUNK_SIZE = 8192;

    private bool $headWritten = false;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly Exchange $exchange,
    ) {}

    /**
     * @throws ResponseDiscardedException The host closed the exchange: the client left or the deadline passed.
     * @throws OutputBufferException The body left the output buffer stack in a state that cannot be restored.
     */
    public function emit(Request $request, Response $response): void
    {
        $this->send($request, $response);
    }

    /**
     * The state prepare() leaves in a BinaryFileResponse, which it keeps to itself.
     */
    private static function fileState(BinaryFileResponse $response, string $property): mixed
    {
        return (new \ReflectionProperty(BinaryFileResponse::class, $property))->getValue($response);
    }

    /**
     * A broken buffer stack outweighs any failure of the response itself: the process cannot serve on.
     *
     * @psalm-pure
     */
    private static function firstFailure(?\Throwable $first, \Throwable $next): \Throwable
    {
        return $next instanceof OutputBufferException && !$first instanceof OutputBufferException
            ? $next
            : $first ?? $next;
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private static function headers(Response $response): array
    {
        $headers = [];
        /**
         * @var non-empty-string $name
         * @var list<string|null> $values
         */
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $headers[$name][] = (string) $value;
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }

        return $headers;
    }

    private function send(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 && $status !== 101) {
            throw new \LogicException('A terminal Symfony response cannot use an interim HTTP status.');
        }
        // It closes every output buffer it can after each event, so none is left to capture the next one.
        if ($response instanceof EventStreamResponse) {
            throw new \LogicException('Symfony EventStreamResponse is not supported in Rapira Dispatcher mode.');
        }

        // Whether the host sends a body depends on the method on the wire. Symfony's getMethod() uppercases
        // it and honours overrides, so it may call HEAD what the wire does not.
        $head = $this->exchange->getRequest()->method === 'HEAD';
        if (!$head && $request->isMethod('HEAD')) {
            $request = clone $request;
            $request->setMethod('GET');
        }

        $response->prepare($request);
        // prepare() sets the length of the file before it knows the status that sends none of it.
        if ($response instanceof BinaryFileResponse && !$response->isSuccessful()) {
            $response->headers->remove('Content-Length');
        }

        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $headers = self::headers($response);

        if ($head || $response->isEmpty()) {
            $this->writeHead($status, $headers);
            // Sends nothing after prepare(), but deletes the file of deleteFileAfterSend().
            $response instanceof BinaryFileResponse and $this->sendContent($response, null);
            $this->writeBody('');

            return;
        }

        if ($response instanceof BinaryFileResponse && $this->sendFile($response, $status, $headers)) {
            return;
        }

        $content = $response->getContent();
        if ($content !== false) {
            $this->writeHead($status, $headers);
            $this->writeBody($content);

            return;
        }

        $this->sendContent($response, function (string $chunk) use ($status, $headers): void {
            $this->headWritten or $this->writeHead($status, $headers);
            $this->writeBody($chunk, false);
        });
        $this->headWritten or $this->writeHead($status, $headers);
        $this->writeBody('');
    }

    /**
     * Hands the file to the host. False when PHP has to send it instead, with the head possibly written.
     *
     * @param int<100, 599> $status
     * @param array<non-empty-string, list<string>> $headers
     */
    private function sendFile(BinaryFileResponse $response, int $status, array $headers): bool
    {
        /** @var int $offset */
        $offset = self::fileState($response, 'offset');
        /** @var int $maxlen */
        $maxlen = self::fileState($response, 'maxlen');
        /** @var bool $deleteAfterSend */
        $deleteAfterSend = self::fileState($response, 'deleteFileAfterSend');
        /** @var \SplTempFileObject|null $tempFile */
        $tempFile = self::fileState($response, 'tempFileObject');

        // The host reads the file after this worker has moved on, so one deleted after sending has to be
        // sent by PHP. So does a temporary file or a stream, which have no path the host can open, and a
        // status that sends no file.
        $path = $response->getFile()->getRealPath();
        if (!$response->isSuccessful() || $deleteAfterSend || $tempFile !== null || $path === false || $maxlen === 0) {
            return false;
        }

        $this->writeHead($status, $headers);
        try {
            $this->exchange->sendFile($path, \max(0, $offset), $maxlen > 0 ? $maxlen : null);
        } catch (FileNotSendableException) {
            // Outside the host's sendfile root, say. Raised before anything is written.
            return false;
        } catch (WorkDiscardedException $exception) {
            throw new ResponseDiscardedException($exception);
        }

        return true;
    }

    /**
     * Runs {@see Response::sendContent()} inside an output buffer of its own and passes what it prints
     * to $forward.
     *
     * A throw out of an output handler does not reach the code that printed, so the first failure is
     * kept, the rest of the output is dropped, and the failure is raised once the body returns. The body
     * is not told: `connection_aborted()` reports the SAPI's connection, not the exchange.
     *
     * @param null|\Closure(string): void $forward Null drops the output.
     */
    private function sendContent(Response $response, ?\Closure $forward): void
    {
        $baseLevel = \ob_get_level();
        /** @var \Throwable|null $failure */
        $failure = null;

        \ob_start(static function (string $chunk, int $phase) use ($forward, &$failure): string {
            if ($forward === null || $failure !== null || $chunk === '' || ($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }

            try {
                $forward($chunk);
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            return '';
        }, self::CHUNK_SIZE);
        $ownedLevel = $baseLevel + 1;

        try {
            $response->sendContent();
            $this->closeBuffersAbove($ownedLevel);
            \ob_get_level() === $ownedLevel
                or throw new OutputBufferException('The response removed the Dispatcher output buffer.');
            @\ob_end_flush() or throw new OutputBufferException('The Dispatcher output buffer could not be flushed.');
        } catch (\Throwable $exception) {
            $failure = self::firstFailure($failure, $exception);
        } finally {
            try {
                $this->restoreBufferLevel($baseLevel);
            } catch (OutputBufferException $exception) {
                $failure = self::firstFailure($failure, $exception);
            }
        }

        $failure === null or throw $failure;
    }

    private function closeBuffersAbove(int $targetLevel): void
    {
        while (\ob_get_level() > $targetLevel) {
            @\ob_end_flush() or throw new OutputBufferException('A nested output buffer cannot be removed safely.');
        }
    }

    private function restoreBufferLevel(int $targetLevel): void
    {
        while (\ob_get_level() > $targetLevel) {
            @\ob_end_clean() or throw new OutputBufferException('The output buffer stack cannot be restored safely.');
        }

        \ob_get_level() === $targetLevel
            or throw new OutputBufferException('The response removed an output buffer it does not own.');
    }

    /**
     * @param int<100, 599> $status
     * @param array<non-empty-string, list<string>> $headers
     */
    private function writeHead(int $status, array $headers): void
    {
        try {
            $this->exchange->writeHead($status, $headers);
        } catch (WorkDiscardedException $exception) {
            throw new ResponseDiscardedException($exception);
        }

        $this->headWritten = true;
    }

    private function writeBody(string $content, bool $eos = true): void
    {
        try {
            $this->exchange->writeBody($content, $eos);
        } catch (WorkDiscardedException $exception) {
            throw new ResponseDiscardedException($exception);
        }
    }
}
