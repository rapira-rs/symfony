<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Symfony\Tests\Acceptance\Support\ServerRequests;
use Testo\Assert;
use Testo\Test;

/**
 * Real HTTP requests against `tests/App` served by the `rapira` binary in {@see Mode::Dispatcher}.
 */
#[Test]
#[RunRapira(mode: Mode::Dispatcher, worker: 'public/index.php', address: self::ADDRESS, readyTimeout: 15.0)]
final class DispatcherModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8083';

    public function flushingStreamArrivesWholeAndTheApplicationGoesOn(): void
    {
        $this->assertFlushingStreamArrivesWhole(sameWorker: true);
    }

    public function failedStreamTerminatesWithoutRestartingTheWorker(): void
    {
        [, $boot] = $this->request('/boot');
        $body = '';
        $curl = \curl_init('http://' . self::ADDRESS . '/broken-stream');
        \curl_setopt_array($curl, [
            \CURLOPT_TIMEOUT => 10,
            \CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body): int {
                $body .= $chunk;

                return \strlen($chunk);
            },
        ]);

        Assert::false(\curl_exec($curl), 'An unfinished response must fail on the wire.');
        Assert::same(\curl_getinfo($curl, \CURLINFO_HTTP_CODE), 200);
        Assert::same($body, 'partial');
        Assert::same($this->request('/boot'), [200, $boot]);
        [, $terminated] = $this->request('/termination');
        Assert::same(\json_decode($terminated, true, flags: \JSON_THROW_ON_ERROR), ['stream' => true]);
    }

    protected function mode(): Mode
    {
        return Mode::Dispatcher;
    }
}
