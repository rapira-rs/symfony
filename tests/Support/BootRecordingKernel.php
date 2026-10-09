<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A Symfony kernel whose boot is recorded in a {@see TestKernel}'s events and which hands every request
 * to that kernel, without building a container.
 */
final class BootRecordingKernel extends Kernel
{
    public function __construct(
        public readonly TestKernel $inner,
        private readonly ?\Throwable $bootFailure = null,
    ) {
        parent::__construct('test', false);
    }

    #[\Override]
    public function boot(): void
    {
        $this->inner->events[] = 'boot';
        $this->bootFailure === null or throw $this->bootFailure;
    }

    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return $this->inner->handle($request, $type, $catch);
    }

    #[\Override]
    public function terminate(Request $request, Response $response): void
    {
        $this->inner->terminate($request, $response);
    }

    #[\Override]
    public function registerBundles(): iterable
    {
        return [];
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
