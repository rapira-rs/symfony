<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * A response failed before its head was written, so another one can still answer the exchange.
 *
 * @internal
 */
final class UnsentResponseException extends \RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(\Throwable $previous)
    {
        parent::__construct('The response failed before its head was written.', 0, $previous);
    }
}
