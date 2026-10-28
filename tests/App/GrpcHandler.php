<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\App;

use Rapira\Grpc\Status;
use Rapira\Grpc\StatusCode;
use Rapira\Grpc\UnaryCall;
use Rapira\Symfony\Grpc\UnaryCallHandlerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Echoes binary protobuf without requiring a protobuf library in the runtime package.
 */
final class GrpcHandler implements UnaryCallHandlerInterface, ResetInterface
{
    private readonly string $bootId;
    private int $requests = 0;

    public function __construct()
    {
        $this->bootId = \bin2hex(\random_bytes(8));
    }

    #[\Override]
    public function handle(UnaryCall $call): void
    {
        ++$this->requests;
        $metadata = $call->getResponseMetadata();
        $metadata->addHeader('x-boot-id', $this->bootId);
        $metadata->addHeader('x-request-count', (string) $this->requests);
        $metadata->addTrailer('x-request-id', $call->getContext()->metadata->entries['x-request-id'][0] ?? 'none');
        if ($call->getMessage() === "\x0a\x06reject") {
            $call->fail(new Status(StatusCode::InvalidArgument, 'Rejected.'));
        } elseif ($call->getMessage() === "\x0a\x05crash") {
            throw new \RuntimeException('private backend address');
        } else {
            $call->respond($call->getMessage());
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->requests = 0;
    }
}
