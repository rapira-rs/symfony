<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Rapira\Symfony\Tests\Acceptance\Support\ServerRequests;
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

    protected function mode(): Mode
    {
        return Mode::Classic;
    }
}
