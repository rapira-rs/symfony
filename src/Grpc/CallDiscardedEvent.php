<?php

declare(strict_types=1);

namespace Rapira\Symfony\Grpc;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Call;

/**
 * Dispatched when serving a call throws WorkDiscardedException, before services are reset.
 * The call is no longer writable. Listeners may observe the disconnect or finish application cleanup.
 * Calls already cancelled at receive time are skipped and do not emit this event.
 *
 * @api
 * @psalm-immutable
 */
final readonly class CallDiscardedEvent
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public Call $call,
        public WorkDiscardedException $exception,
    ) {}
}
