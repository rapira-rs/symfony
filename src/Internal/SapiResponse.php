<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a response through the SAPI, the way it goes out under php-fpm.
 *
 * @internal
 */
final class SapiResponse
{
    /** Bytes the buffer holds before it passes them on, as `output_buffering` does under php-fpm. */
    private const CHUNK_SIZE = 8192;

    /**
     * Sends $response inside an output buffer of its own.
     *
     * A streamed callback commonly calls `ob_flush()`, which needs an open buffer: php-fpm has one from
     * `output_buffering`, the Rapira SAPI starts without. Without it `ob_flush()` raises a notice that
     * Symfony's error handler turns into an exception halfway through the body.
     */
    public static function send(Response $response): void
    {
        $level = \ob_get_level();
        \ob_start(null, self::CHUNK_SIZE);

        try {
            // Not send(true): under a server SAPI it closes every buffer, those it does not own included.
            $response->send(false);
        } finally {
            // Buffers the body opened and left behind are flushed as the SAPI would at the end of a request;
            // one that cannot be removed stays until the request ends.
            while (\ob_get_level() > $level && @\ob_end_flush());
        }
    }
}
