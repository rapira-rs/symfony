<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\App;

use Rapira\Symfony\Grpc\UnaryCallHandlerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A compiled Symfony container with its real services_resetter, shared across native calls.
 */
final class GrpcKernel extends Kernel
{
    #[\Override]
    public function registerBundles(): iterable
    {
        return [];
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register(GrpcHandler::class);
            $container->setAlias(UnaryCallHandlerInterface::class, GrpcHandler::class)->setPublic(true);
            $container->register('services_resetter', ServicesResetter::class)
                ->setPublic(true)
                ->setArguments([
                    new IteratorArgument([GrpcHandler::class => new Reference(GrpcHandler::class)]),
                    [GrpcHandler::class => 'reset'],
                ]);
        });
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2) . '/runtime/grpc-cache';
    }

    #[\Override]
    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2) . '/runtime/grpc-log';
    }
}
