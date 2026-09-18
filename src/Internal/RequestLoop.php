<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * @internal
 * @psalm-mutable
 */
interface RequestLoop
{
    /**
     * @param callable(): bool $handler
     * @psalm-impure
     */
    public function handle(callable $handler): bool;
}
