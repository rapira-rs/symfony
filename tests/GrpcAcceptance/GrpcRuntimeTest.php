<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\GrpcAcceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Assert;
use Testo\Test;

/**
 * Native gRPC plugin calls over gRPC-Web, using curl already required by the acceptance suite.
 */
#[Test]
#[RunRapira(mode: Mode::Dispatcher, worker: 'public/index.php', address: '127.0.0.1:8084', readyTimeout: 15.0)]
final class GrpcRuntimeTest
{
    public function binaryCallsPreserveMetadataAndResetTheRealContainer(): void
    {
        [$first, $headers, $trailers] = self::call('first', 'trace-first');
        [$second, $nextHeaders, $nextTrailers] = self::call('second');

        Assert::same($first, "\x0a\x05first");
        Assert::same($second, "\x0a\x06second");
        Assert::same($headers['x-boot-id'], $nextHeaders['x-boot-id']);
        Assert::same($headers['x-request-count'], '1');
        Assert::same($nextHeaders['x-request-count'], '1');
        Assert::string($trailers)->contains('grpc-status: 0')->contains('x-request-id: trace-first');
        Assert::string($nextTrailers)->contains('x-request-id: none');
    }

    public function rejectedCallDoesNotRestartTheWorker(): void
    {
        [, $before] = self::call('before');
        [$body, , $trailers] = self::call('reject');
        [, $after] = self::call('after');

        Assert::same($body, '');
        Assert::string($trailers)->contains('grpc-status: 3');
        Assert::same($before['x-boot-id'], $after['x-boot-id']);
        Assert::same($after['x-request-count'], '1');
    }

    public function unexpectedFailureIsSanitizedAndRecyclesTheWorker(): void
    {
        [, $before] = self::call('before');
        [$body, , $trailers] = self::call('crash');
        [, $after] = self::call('after');

        Assert::same($body, '');
        Assert::string($trailers)->contains('grpc-status: 13')->notContains('private');
        Assert::notSame($before['x-boot-id'], $after['x-boot-id']);
        Assert::same($after['x-request-count'], '1');
    }

    /**
     * @return array{string, array<string, string>, string} Message bytes, HTTP headers, gRPC trailers.
     */
    private static function call(string $value, ?string $requestId = null): array
    {
        // EchoMessage has one short string field, so no generated protobuf client is needed here.
        $message = "\x0a" . \chr(\strlen($value)) . $value;
        $headers = [];
        $requestHeaders = ['Content-Type: application/grpc-web+proto', 'X-Grpc-Web: 1'];
        if ($requestId !== null) {
            $requestHeaders[] = 'x-request-id: ' . $requestId;
        }
        $curl = \curl_init('http://127.0.0.1:8085/rapira.test.Echo/Ping');
        \curl_setopt_array($curl, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => "\x00" . \pack('N', \strlen($message)) . $message,
            \CURLOPT_HTTPHEADER => $requestHeaders,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 15,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
                if (\str_contains($line, ':')) {
                    [$name, $value] = \explode(':', $line, 2);
                    $headers[\strtolower($name)] = \trim($value);
                }

                return \strlen($line);
            },
        ]);
        $body = \curl_exec($curl);
        Assert::same(\curl_getinfo($curl, \CURLINFO_HTTP_CODE), 200, \curl_error($curl));
        Assert::string($body);
        $message = $trailers = '';
        for ($offset = 0; $offset < \strlen($body);) {
            $flags = \ord($body[$offset]);
            $length = \unpack('N', \substr($body, $offset + 1, 4))[1];
            $frame = \substr($body, $offset + 5, $length);
            if (($flags & 0x80) !== 0) {
                $trailers .= $frame;
            } else {
                $message .= $frame;
            }
            $offset += 5 + $length;
        }

        return [$message, $headers, $trailers];
    }
}
