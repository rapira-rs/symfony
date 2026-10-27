<?php

declare(strict_types=1);

namespace Rapira\Symfony\Grpc;

use Rapira\Grpc\UnaryCall;

/**
 * The application boundary for a native unary gRPC call.
 *
 * Register a public service under this interface in the kernel's container. The handler owns method
 * routing, protobuf decoding, response metadata and application middleware. It must respond() or
 * fail() before returning, unless the client has left. Expected rejections use fail(); an exception
 * escaping the handler stops the worker after the runtime answers INTERNAL and resets services.
 * Finish application-specific termination inside the handler, including on failure, before returning.
 *
 * @api
 * @psalm-mutable
 */
interface UnaryCallHandlerInterface
{
    /**
     * @psalm-impure
     */
    public function handle(UnaryCall $call): void;
}
