<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;

/**
 * A Symfony request built from an exchange, with the uploaded files it owns until {@see cleanup()}.
 *
 * @internal
 */
final class DispatcherRequest
{
    /**
     * @psalm-mutation-free
     *
     * @param list<string> $uploads
     */
    public function __construct(
        public readonly Request $request,
        private array $uploads,
    ) {}

    /**
     * Removes the files the application left in place; one it moved is no longer there to remove.
     *
     * @param list<string> $paths
     */
    public static function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            \is_file($path) and @\unlink($path);
        }
    }

    public function cleanup(): void
    {
        self::removeFiles($this->uploads);
        $this->uploads = [];
    }
}
