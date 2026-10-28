<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature\Fixture;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Kernel;

final class GrpcKernel extends Kernel
{
    public array $events = [];

    public function __construct(Container $container, private readonly ?\Throwable $bootFailure = null)
    {
        parent::__construct('test', false);
        $this->container = $container;
    }

    #[\Override]
    public function boot(): void
    {
        $this->events[] = 'boot';
        $this->bootFailure === null or throw $this->bootFailure;
    }

    #[\Override]
    public function shutdown(): void
    {
        $this->events[] = 'shutdown';
    }

    #[\Override]
    public function registerBundles(): iterable
    {
        return [];
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
