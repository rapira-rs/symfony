<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Stub;

use Symfony\Contracts\Service\ResetInterface;

final class RecordingResetter implements ResetInterface
{
    public int $resets = 0;

    public function __construct(private readonly ?\Closure $reset = null) {}

    #[\Override]
    public function reset(): void
    {
        ++$this->resets;
        ($this->reset)?->__invoke();
    }
}
