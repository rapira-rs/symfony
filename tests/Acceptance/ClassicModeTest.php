<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Symfony\Tests\Acceptance\Support\ServerRequests;
use Testo\Skip;
use Testo\Test;

/**
 * Real HTTP requests against `tests/App` served by the `rapira` binary in {@see Mode::Classic}.
 */
#[Test]
#[RunRapira(mode: Mode::Classic, worker: 'public/index.php', address: self::ADDRESS, readyTimeout: 15.0)]
final class ClassicModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8081';

    #[Skip('known bug: ob_flush() in a StreamedResponse callback fails without an output buffer and cuts the response')]
    public function flushingStreamArrivesWholeAndTheApplicationGoesOn(): void
    {
        $this->assertFlushingStreamArrivesWhole(sameWorker: false);
    }

    protected function mode(): Mode
    {
        return Mode::Classic;
    }
}
