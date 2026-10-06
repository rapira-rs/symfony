<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Request as RapiraRequest;
use Rapira\InetAddress;
use Rapira\Symfony\Internal\ExchangeResponseEmitter;
use Rapira\Symfony\Internal\ResponseDiscardedException;
use Rapira\Symfony\Tests\Support\IsolatesProcessState;
use Rapira\Symfony\Tests\Support\StubExchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class ExchangeResponseEmitterTest
{
    use IsolatesProcessState;

    public function emitsOrdinaryResponseOnceWithRepeatedHeadersAndCookies(): void
    {
        $exchange = $this->exchange();
        $response = new Response('body', 201, ['X-Repeat' => ['one', 'two']]);
        $response->headers->setCookie(new Cookie('a', 'one'));
        $response->headers->setCookie(new Cookie('b', 'two'));

        (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), $response);

        Assert::same($exchange->heads[0]['status'], 201);
        Assert::same($exchange->heads[0]['headers']['X-Repeat'], ['one', 'two']);
        Assert::count($exchange->heads[0]['headers']['Set-Cookie'], 2);
        Assert::same($exchange->bodies, [['content' => 'body', 'eos' => true]]);
        Assert::same($exchange->cancellationChecks, 0);
    }

    public function ordinaryHeadRetainsPreparedContentLengthAndEmitsNoBody(): void
    {
        $exchange = $this->exchange('HEAD');
        $request = Request::create('/', 'HEAD');
        $request->attributes->set('rapira.request_method', 'HEAD');
        $response = new Response('must-not-send', headers: ['Content-Length' => '13']);

        (new ExchangeResponseEmitter())->emit($exchange, $request, $response);

        Assert::same($exchange->heads[0]['headers']['Content-Length'], ['13']);
        Assert::same($exchange->bodies, [['content' => '', 'eos' => true]]);
    }

    public function lowercaseHeadIsNotGivenStandardHeadTransportSemantics(): void
    {
        $exchange = $this->exchange('head');
        $request = Request::create('/', 'head');
        $request->attributes->set('rapira.request_method', 'head');

        (new ExchangeResponseEmitter())->emit($exchange, $request, new Response('body'));

        Assert::same($exchange->bodies, [['content' => 'body', 'eos' => true]]);
    }

    public function emptyResponsesEmitNoBody(): void
    {
        foreach ([204, 304] as $status) {
            $exchange = $this->exchange();
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), new Response('must-not-run', $status));
            Assert::same($exchange->bodies, [['content' => '', 'eos' => true]]);
            Assert::false(isset($exchange->heads[0]['headers']['Content-Length']));
        }
    }

    public function rejectsTerminalInterimResponsesBeforeWriting(): void
    {
        $exchange = $this->exchange();
        Expect::exception(\LogicException::class)
            ->withMessage('A terminal Symfony response cannot use an interim HTTP status.');
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), new Response('', 103));
        } finally {
            Assert::same($exchange->heads, []);
            Assert::same($exchange->bodies, []);
        }
    }

    public function streamsProgressiveChunksAndRestoresOuterBuffer(): void
    {
        $exchange = $this->exchange();
        $response = new StreamedResponse(static function (): void {
            echo 'one';
            \ob_flush();
            echo 'two';
        });
        $level = \ob_get_level();
        \ob_start();
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), $response);
            Assert::same(\ob_get_level(), $level + 1);
            Assert::same(\ob_get_contents(), '');
        } finally {
            \ob_end_clean();
        }

        Assert::same($exchange->bodies, [
            ['content' => 'one', 'eos' => false],
            ['content' => 'two', 'eos' => false],
            ['content' => '', 'eos' => true],
        ]);
    }

    public function capturesStreamingWriteFailureWithoutLeakingToOuterBuffer(): void
    {
        $exchange = $this->exchange();
        $exchange->bodyFailure = new WorkDiscardedException('gone');
        $response = new StreamedResponse(static function (): void {
            echo 'must-not-leak';
            \ob_flush();
            echo 'also-hidden';
        });

        \ob_start();
        Expect::exception(ResponseDiscardedException::class);
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), $response);
        } finally {
            Assert::same(\ob_get_contents(), '');
            \ob_end_clean();
        }
    }

    public function nonRemovableNestedBufferFailsWithoutHanging(): void
    {
        $script = \tempnam(\sys_get_temp_dir(), 'rapira-buffer-test-');
        \file_put_contents($script, <<<'PHP'
<?php
require getcwd() . '/vendor/autoload.php';
$exchange = new Rapira\Symfony\Tests\Support\StubExchange(new Rapira\Http\Request(
    'GET',
    'http://localhost/',
    '/',
    'localhost',
    'HTTP/1.1',
    [],
    '',
    new Rapira\InetAddress('127.0.0.1', 40000),
    new Rapira\InetAddress('127.0.0.1', 8080),
    null,
    0.0,
));
$response = new Symfony\Component\HttpFoundation\StreamedResponse(static function (): void {
    ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
    echo 'trapped';
});
try {
    (new Rapira\Symfony\Internal\ExchangeResponseEmitter())->emit(
        $exchange,
        Symfony\Component\HttpFoundation\Request::create('/'),
        $response,
    );
} catch (LogicException $exception) {
    fwrite(STDERR, $exception->getMessage());
}
PHP);

        try {
            [$status, $output] = $this->runPhpScript($script);
        } finally {
            @\unlink($script);
        }

        Assert::same($status, 0);
        Assert::true(\str_contains($output, 'A nested output buffer cannot be removed safely.'));
    }

    public function eventStreamResponseIsRejectedWhenAvailable(): void
    {
        if (!\class_exists(\Symfony\Component\HttpFoundation\EventStreamResponse::class)) {
            Assert::true(true);

            return;
        }

        $exchange = $this->exchange();
        $response = new \Symfony\Component\HttpFoundation\EventStreamResponse();
        Expect::exception(\LogicException::class)
            ->withMessage('Symfony EventStreamResponse is not supported in Rapira Dispatcher mode.');
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), $response);
        } finally {
            Assert::same($exchange->heads, []);
        }
    }

    public function binaryHeadRetainsFileLengthAndDeleteAfterSendCleansFile(): void
    {
        $path = $this->file('0123456789');
        $exchange = $this->exchange('HEAD');
        $request = Request::create('/file', 'HEAD');
        $request->attributes->set('rapira.request_method', 'HEAD');
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'application/octet-stream']);
        $response->deleteFileAfterSend();

        (new ExchangeResponseEmitter())->emit($exchange, $request, $response);

        Assert::same($exchange->heads[0]['headers']['Content-Length'], ['10']);
        Assert::same($exchange->bodies, [['content' => '', 'eos' => true]]);
        Assert::false(\is_file($path));
    }

    public function emptyBinaryResponseRunsDeleteAfterSendCleanup(): void
    {
        foreach ([204, 304] as $status) {
            $path = $this->file('cleanup');
            $response = new BinaryFileResponse(
                $path,
                $status,
                ['Content-Type' => 'application/octet-stream'],
            );
            $response->deleteFileAfterSend();
            $exchange = $this->exchange();

            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/file'), $response);

            Assert::same($exchange->bodies, [['content' => '', 'eos' => true]]);
            Assert::false(\is_file($path));
        }
    }

    public function emitsBinaryFilesAndRangesThroughSymfonySendContent(): void
    {
        $path = $this->file('0123456789');
        $exchange = $this->exchange();
        $request = Request::create('/file', 'GET', server: ['HTTP_RANGE' => 'bytes=2-5']);
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'application/octet-stream']);
        $response->setChunkSize(2);
        (new ExchangeResponseEmitter())->emit($exchange, $request, $response);

        Assert::same($exchange->heads[0]['status'], 206);
        Assert::same($exchange->heads[0]['headers']['Content-Range'], ['bytes 2-5/10']);
        Assert::same(\implode('', \array_column($exchange->bodies, 'content')), '2345');
        Assert::true($exchange->finalized);

        $unsatisfiable = $this->exchange();
        $unsatisfiableRequest = Request::create('/file', 'GET', server: ['HTTP_RANGE' => 'bytes=999-1000']);
        $unsatisfiableResponse = new BinaryFileResponse(
            $path,
            headers: ['Content-Type' => 'application/octet-stream'],
        );
        (new ExchangeResponseEmitter())->emit($unsatisfiable, $unsatisfiableRequest, $unsatisfiableResponse);
        Assert::same($unsatisfiable->heads[0]['status'], 416);
        Assert::false(isset($unsatisfiable->heads[0]['headers']['Content-Length']));
        Assert::same($unsatisfiable->bodies, [['content' => '', 'eos' => true]]);

        @\unlink($path);
    }

    /**
     * @return array{int, string}
     */
    private function runPhpScript(string $script): array
    {
        $process = \proc_open(
            [\PHP_BINARY, $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the PHP subprocess.');
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $output = '';
        $exitCode = null;
        $deadline = \microtime(true) + 5;
        try {
            do {
                $output .= \stream_get_contents($pipes[1]);
                $output .= \stream_get_contents($pipes[2]);
                $status = \proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (\microtime(true) >= $deadline) {
                    throw new \RuntimeException('PHP subprocess timed out.');
                }
                \usleep(10_000);
            } while (true);

            $output .= \stream_get_contents($pipes[1]);
            $output .= \stream_get_contents($pipes[2]);
        } finally {
            if (\proc_get_status($process)['running']) {
                \proc_terminate($process);
            }
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $closeStatus = \proc_close($process);
        }

        return [$exitCode === -1 ? $closeStatus : $exitCode, $output];
    }

    private function file(string $content): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rapira-binary-');
        \file_put_contents($path, $content);

        return $path;
    }

    private function exchange(string $method = 'GET'): StubExchange
    {
        return new StubExchange(new RapiraRequest(
            $method,
            'http://localhost/',
            '/',
            'localhost',
            'HTTP/1.1',
            [],
            '',
            new InetAddress('127.0.0.1', 40000),
            new InetAddress('127.0.0.1', 8080),
            null,
            0.0,
        ));
    }
}
