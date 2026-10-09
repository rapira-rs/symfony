<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Symfony\Tests\Acceptance\Support\ServerRequests;
use Testo\Assert;
use Testo\Test;

/**
 * Real HTTP requests against `tests/App` served by the `rapira` binary in {@see Mode::Worker}.
 */
#[Test]
#[RunRapira(mode: Mode::Worker, worker: 'public/index.php', address: self::ADDRESS, readyTimeout: 15.0)]
final class WorkerModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8082';

    public function flushingStreamArrivesWholeAndTheApplicationGoesOn(): void
    {
        $this->assertFlushingStreamArrivesWhole(sameWorker: true);
    }

    public function uploadedFileRemainsReadableDuringTerminate(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rapira-upload-test-');
        \file_put_contents($path, 'upload survives until terminate');
        try {
            Assert::same($this->request('/upload', [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => ['file' => new \CURLFile($path, 'text/plain', 'note.txt')],
            ]), [200, 'uploaded']);
            [$status, $body] = $this->request('/termination');
        } finally {
            @\unlink($path);
        }

        Assert::same($status, 200);
        Assert::same(\json_decode($body, true, flags: \JSON_THROW_ON_ERROR), ['exists' => true, 'content' => 'upload survives until terminate']);
    }

    public function executionTimeoutStillAppliesDuringTerminate(): void
    {
        [, $boot] = $this->request('/boot');

        Assert::same($this->request('/terminate-timeout'), [200, $boot]);
        [$status, $nextBoot] = $this->request('/boot');

        Assert::same($status, 200);
        Assert::notSame($nextBoot, $boot, 'The timed-out worker must be replaced before serving another request.');
    }

    protected function mode(): Mode
    {
        return Mode::Worker;
    }
}
