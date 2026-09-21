<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * @internal
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 */
interface RequestLoop
{
    /**
     * @psalm-suppress MissingAbstractPureAnnotation
     *
     * @param callable(): bool $handler
     */
    public function handle(callable $handler): bool;
}
