<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class DispatcherRequest
{
    /**
     * @psalm-mutation-free
     *
     * @param list<string> $uploadPaths
     */
    public function __construct(
        public readonly Request $request,
        private array $uploadPaths,
    ) {}

    public function cleanup(): void
    {
        foreach ($this->uploadPaths as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        $this->uploadPaths = [];
    }
}
