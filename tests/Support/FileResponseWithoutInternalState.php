<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Models a future BinaryFileResponse with the public streaming API but no current internal properties.
 * Aliased to that class only in an isolated compatibility test; production uses the real Symfony class.
 */
final class FileResponseWithoutInternalState extends Response
{
    #[\Override]
    public function getContent(): false
    {
        return false;
    }
}
