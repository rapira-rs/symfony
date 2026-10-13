<?php

declare(strict_types=1);

use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Runtime;
use Rapira\Symfony\Tests\Support\FileResponseWithoutInternalState;
use Rapira\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

// Minimum dependencies emit PHP deprecations when vendor classes are loaded, including lazily.
// Keep them out of this subprocess's JSON protocol while preserving application diagnostics.
\set_error_handler(static fn(int $severity, string $message, string $file): bool =>
    $severity === E_DEPRECATED && \str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR));
require \dirname(__DIR__, 2) . '/vendor/autoload.php';

\class_alias(FileResponseWithoutInternalState::class, BinaryFileResponse::class);
$response = new FileResponseWithoutInternalState('file-body', headers: ['Content-Type' => 'text/plain']);
$exchange = FakeExchange::for('/file');
$host = (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher($exchange)))->install();
$runtime = new Runtime(['debug' => false, 'error_handler' => false, 'env' => 'test']);
$result = $runtime->getRunner(new TestKernel(static fn(): Response => $response))->run();

echo \json_encode([
    'result' => $result,
    'status' => $exchange->status,
    'body' => $exchange->getBody(),
    'finalized' => $exchange->isFinalized(),
    'sentFiles' => $exchange->sentFiles,
    'logs' => $host->logs,
], \JSON_THROW_ON_ERROR);
