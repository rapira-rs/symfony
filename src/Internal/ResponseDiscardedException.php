<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Exception\WorkDiscardedException;

/**
 * @internal
 */
final class ResponseDiscardedException extends \RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(?WorkDiscardedException $previous = null)
    {
        parent::__construct('The HTTP response was discarded.', 0, $previous);
    }
}
