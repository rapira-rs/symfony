<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\ReadCountingStream;
use Rapira\Symfony\Tests\Support\ScriptedExchange;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Skip;
use Testo\Test;

/**
 * How a Symfony response reaches the exchange in {@see Mode::Dispatcher}.
 */
#[Test]
final class DispatcherResponseTest
{
    use FakeRuntimeLifecycle;

    #[AfterTest]
    public function unregisterStreamWrapper(): void
    {
        ReadCountingStream::unregister();
    }

    public function ordinaryResponseIsWrittenWithRepeatedHeadersAndCookies(): void
    {
        $exchange = FakeExchange::for('/');
        $response = new Response('body', 201, ['X-Repeat' => ['one', 'two']]);
        $response->headers->setCookie(new Cookie('a', 'one'));
        $response->headers->setCookie(new Cookie('b', 'two'));

        $this->serve(static fn(): Response => $response, $exchange);

        Assert::same($exchange->status, 201);
        Assert::same($exchange->headers['X-Repeat'], ['one', 'two']);
        Assert::count($exchange->headers['Set-Cookie'], 2);
        Assert::same($exchange->chunks, ['body']);
        Assert::true($exchange->isFinalized());
    }

    public function headRequestKeepsThePreparedContentLengthAndSendsNoBody(): void
    {
        $exchange = FakeExchange::for('/', 'HEAD');

        $this->serve(static fn(): Response => new Response('must-not-send', headers: ['Content-Length' => '13']), $exchange);

        Assert::same($exchange->headers['Content-Length'], ['13']);
        Assert::same($exchange->getBody(), '');
        Assert::true($exchange->isFinalized());
    }

    public function lowercaseHeadIsNotTreatedAsHead(): void
    {
        $exchange = FakeExchange::for('/', 'head');

        $this->serve(static fn(): Response => new Response('body'), $exchange);

        Assert::same($exchange->getBody(), 'body');
        Assert::true($exchange->isFinalized());
    }

    public function emptyStatusesSendNoBody(): void
    {
        foreach ([204, 304] as $status) {
            $exchange = FakeExchange::for('/');

            $this->serve(static fn(): Response => new Response('must-not-send', $status), $exchange);

            Assert::same($exchange->status, $status);
            Assert::same($exchange->getBody(), '');
            Assert::null($exchange->header('content-length'));
            Assert::true($exchange->isFinalized());
        }
    }

    public function streamedResponseIsForwardedAsItIsFlushedAndLeavesTheOuterBufferAlone(): void
    {
        $exchange = FakeExchange::for('/');
        $response = new StreamedResponse(static function (): void {
            echo 'one';
            \ob_flush();
            echo 'two';
        });
        $level = \ob_get_level();

        \ob_start();
        try {
            $this->serve(static fn(): Response => $response, $exchange);
            Assert::same(\ob_get_level(), $level + 1);
            Assert::same(\ob_get_contents(), '');
        } finally {
            \ob_end_clean();
        }

        Assert::same(\array_values(\array_filter($exchange->chunks, static fn(string $chunk): bool => $chunk !== '')), ['one', 'two']);
        Assert::true($exchange->isFinalized());
    }

    public function streamClosedByTheHostLeaksNothingAndTheLoopGoesOn(): void
    {
        $gone = new ScriptedExchange(FakeExchange::for('/gone'));
        $gone->bodyFailure = new WorkDiscardedException('gone');
        $next = FakeExchange::for('/next');
        $kernel = new TestKernel(static fn(): Response => new StreamedResponse(static function (): void {
            echo 'must-not-leak';
            \ob_flush();
            echo 'also-hidden';
        }));

        \ob_start();
        try {
            $this->serveWith($kernel, $gone, $next);
            Assert::same(\ob_get_contents(), '');
        } finally {
            \ob_end_clean();
        }

        Assert::same($kernel->events, ['handle:/gone', 'terminate:/gone', 'handle:/next', 'terminate:/next']);
        Assert::true($next->isFinalized());
    }

    public function outputBufferTheResponseCannotRemoveFailsWithoutHanging(): void
    {
        $script = \tempnam(\sys_get_temp_dir(), 'rapira-buffer-test-');
        \file_put_contents($script, <<<'PHP'
            <?php
            require getcwd() . '/vendor/autoload.php';

            use Rapira\Mode;
            use Rapira\Sdk\Testing\Double\FakeRuntime;
            use Rapira\Sdk\Testing\Double\Http\FakeExchange;
            use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
            use Rapira\Symfony\Runtime;
            use Rapira\Symfony\Tests\Support\TestKernel;
            use Symfony\Component\HttpFoundation\StreamedResponse;

            (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher(FakeExchange::for('/'))))->install();
            $kernel = new TestKernel(static fn() => new StreamedResponse(static function (): void {
                ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
                echo 'trapped';
            }));
            try {
                (new Runtime(['debug' => false, 'error_handler' => false, 'env' => 'test']))->getRunner($kernel)->run();
            } catch (LogicException $exception) {
                fwrite(STDERR, $exception->getMessage());
            }
            PHP);

        try {
            [$status, $output] = self::runPhpScript($script);
        } finally {
            @\unlink($script);
        }

        Assert::same($status, 0);
        Assert::string($output)->contains('A nested output buffer cannot be removed safely.');
    }

    public function headRequestForAFileSendsItsLengthAndStillDeletesIt(): void
    {
        $path = self::file('0123456789');
        $exchange = FakeExchange::for('/file', 'HEAD');

        $this->serve(static fn(): Response => (new BinaryFileResponse(
            $path,
            headers: ['Content-Type' => 'application/octet-stream'],
        ))->deleteFileAfterSend(), $exchange);

        Assert::same($exchange->status, 200);
        Assert::same($exchange->headers['Content-Length'], ['10']);
        Assert::same(self::deliveredBody($exchange), '');
        Assert::true($exchange->isFinalized());
        Assert::false(\is_file($path));
    }

    #[Skip('known bug: a HEAD request reads the whole file of a BinaryFileResponse')]
    public function headRequestForAFileDoesNotReadIt(): void
    {
        $path = self::file('0123456789');
        ReadCountingStream::register();
        $exchange = FakeExchange::for('/file', 'HEAD');

        $this->serve(static fn(): Response => (new BinaryFileResponse(
            ReadCountingStream::path($path),
            headers: ['Content-Type' => 'application/octet-stream'],
        ))->deleteFileAfterSend(), $exchange);

        Assert::same(ReadCountingStream::$bytesRead, 0);
        Assert::same($exchange->status, 200);
        Assert::same($exchange->headers['Content-Length'], ['10']);
        Assert::same(self::deliveredBody($exchange), '');
        Assert::true($exchange->isFinalized());
        Assert::false(\is_file($path));
    }

    public function emptyFileResponseStillDeletesTheFile(): void
    {
        foreach ([204, 304] as $status) {
            $path = self::file('cleanup');
            $exchange = FakeExchange::for('/file');

            $this->serve(static fn(): Response => (new BinaryFileResponse(
                $path,
                $status,
                ['Content-Type' => 'application/octet-stream'],
            ))->deleteFileAfterSend(), $exchange);

            Assert::same($exchange->status, $status);
            Assert::same(self::deliveredBody($exchange), '');
            Assert::true($exchange->isFinalized());
            Assert::false(\is_file($path));
        }
    }

    public function fileRangesAreServed(): void
    {
        $path = self::file('0123456789');
        $respond = static fn(): Response => (new BinaryFileResponse(
            $path,
            headers: ['Content-Type' => 'application/octet-stream'],
        ))->setChunkSize(2);
        $range = FakeExchange::for('/file', headers: ['range' => ['bytes=2-5']]);
        $unsatisfiable = FakeExchange::for('/file', headers: ['range' => ['bytes=999-1000']]);

        try {
            $this->serve($respond, $range, $unsatisfiable);
        } finally {
            @\unlink($path);
        }

        Assert::same($range->status, 206);
        Assert::same($range->headers['Content-Range'], ['bytes 2-5/10']);
        Assert::same(self::deliveredBody($range), '2345');
        Assert::true($range->isFinalized());
        Assert::same($unsatisfiable->status, 416);
        Assert::null($unsatisfiable->header('content-length'));
        Assert::same(self::deliveredBody($unsatisfiable), '');
        Assert::true($unsatisfiable->isFinalized());
    }

    /**
     * The body as the client receives it, whether written or handed to the host as a file.
     */
    private static function deliveredBody(FakeExchange $exchange): string
    {
        $body = $exchange->getBody();
        foreach ($exchange->sentFiles as $file) {
            $body .= (string) \file_get_contents($file['path'], offset: $file['offset'], length: $file['length']);
        }

        return $body;
    }

    private static function file(string $content): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rapira-binary-');
        \file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array{int, string} The exit code and the combined output.
     */
    private static function runPhpScript(string $script): array
    {
        $process = \proc_open(
            [\PHP_BINARY, '-dxdebug.mode=off', $script],
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
        $deadline = \microtime(true) + 10;
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

    /**
     * @param \Closure(): Response $respond
     */
    private function serve(\Closure $respond, FakeExchange ...$exchanges): void
    {
        $this->serveWith(new TestKernel($respond), ...$exchanges);
    }

    private function serveWith(TestKernel $kernel, Exchange ...$exchanges): void
    {
        (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher(...$exchanges)))->install();

        Assert::same(self::runtime()->getRunner($kernel)->run(), 0);
    }
}
