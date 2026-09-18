<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * @internal
 */
final readonly class NativeRequestLoop implements RequestLoop
{
    /**
     * @psalm-suppress MissingPureAnnotation
     * @psalm-suppress UndefinedFunction The function is provided by the Rapira extension and its contract.
     * @psalm-suppress MixedReturnStatement
     */
    #[\Override]
    public function handle(callable $handler): bool
    {
        return \Rapira\handle_request($handler);
    }
}
