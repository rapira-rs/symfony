<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Request as RapiraRequest;
use Rapira\InetAddress;
use Rapira\Symfony\Internal\ExchangeResponseEmitter;
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
    }

    public function suppressesPreparedHeadAndEmptyResponseBodies(): void
    {
        foreach ([
            [Request::create('/', 'HEAD'), new Response('must-not-run')],
            [Request::create('/'), new Response('must-not-run', 204)],
            [Request::create('/'), new Response('must-not-run', 304)],
        ] as [$request, $response]) {
            $exchange = $this->exchange($request->getMethod());
            (new ExchangeResponseEmitter())->emit($exchange, $request, $response);
            Assert::same($exchange->bodies, [['content' => '', 'eos' => true]]);
            Assert::false(isset($exchange->heads[0]['headers']['Content-Length']));
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
        } finally {
            \ob_end_clean();
        }

        Assert::same($exchange->bodies, [
            ['content' => 'one', 'eos' => false],
            ['content' => 'two', 'eos' => false],
            ['content' => '', 'eos' => true],
        ]);
    }

    public function restoresBuffersAndPropagatesStreamFailure(): void
    {
        $exchange = $this->exchange();
        $response = new StreamedResponse(static function (): void {
            echo 'before';
            throw new \RuntimeException('stream failed');
        });
        $level = \ob_get_level();

        Expect::exception(\RuntimeException::class)->withMessage('stream failed');
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), $response);
        } finally {
            Assert::same(\ob_get_level(), $level);
            Assert::false($exchange->finalized);
        }
    }

    public function cancellationStopsBeforeCommit(): void
    {
        $exchange = $this->exchange();
        $exchange->cancelled = true;

        Expect::exception(WorkDiscardedException::class);
        try {
            (new ExchangeResponseEmitter())->emit($exchange, Request::create('/'), new Response('body'));
        } finally {
            Assert::same($exchange->heads, []);
            Assert::same($exchange->bodies, []);
        }
    }

    public function emitsBinaryFilesAndRangesThroughSymfonySendContent(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rapira-binary-');
        \file_put_contents($path, '0123456789');

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

        $delete = new BinaryFileResponse($path, headers: ['Content-Type' => 'application/octet-stream']);
        $delete->deleteFileAfterSend();
        (new ExchangeResponseEmitter())->emit($this->exchange(), Request::create('/file'), $delete);
        Assert::false(\is_file($path));
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
