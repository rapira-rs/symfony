<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * The output buffer stack is no longer the one the bridge left, so nothing written from now on would
 * reach the response it is meant for.
 *
 * @internal
 */
final class OutputBufferException extends \LogicException {}
