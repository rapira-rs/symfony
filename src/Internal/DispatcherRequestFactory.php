<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rapira\Http\Exchange;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Sdk\Http\DispatcherRequestFactory as PsrRequestFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final readonly class DispatcherRequestFactory
{
    /** @var array<string, mixed> */
    private array $server;

    private PsrRequestFactory $psrFactory;
    private HttpFoundationFactory $httpFoundationFactory;

    /**
     * @param array<string, mixed>|null $server
     */
    public function __construct(?array $server = null)
    {
        $this->server = \array_filter(
            $server ?? $_SERVER,
            static fn(mixed $key): bool => !\str_starts_with((string) $key, 'HTTP_'),
            \ARRAY_FILTER_USE_KEY,
        );

        $psr17Factory = new Psr17Factory();
        $this->psrFactory = new PsrRequestFactory($psr17Factory, $psr17Factory, $psr17Factory);
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function create(Exchange $exchange): DispatcherRequest
    {
        $source = $exchange->getRequest();
        $psrRequest = $this->psrFactory->create(new RequestExchange(
            $exchange,
            $this->normalizeRequest($source),
        ));
        $psrRequest = $psrRequest->withCookieParams($this->parseCookies($source->headers));

        $request = $this->httpFoundationFactory->createRequest($psrRequest);
        $request->server->add($this->server);
        $request->server->set('REQUEST_URI', $source->target);

        return new DispatcherRequest($request, $this->uploadPaths($request));
    }

    private function normalizeRequest(RapiraRequest $request): RapiraRequest
    {
        /** @var array<non-empty-string, list<string>> $headers */
        $headers = [];
        /** @var array<string, non-empty-string> $names */
        $names = [];
        foreach ($request->headers as $name => $values) {
            $lower = \strtolower($name);
            if (isset($names[$lower])) {
                $headers[$names[$lower]] = [...$headers[$names[$lower]], ...$values];
                continue;
            }

            $names[$lower] = $name;
            $headers[$name] = $values;
        }

        return new RapiraRequest(
            $request->method,
            $request->uri,
            $request->target,
            $request->authority,
            $request->protocol,
            $headers,
            $request->body,
            $request->remote,
            $request->server,
            $request->tls,
            $request->receivedAt,
        );
    }

    /**
     * @psalm-pure
     *
     * @param array<non-empty-string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private function parseCookies(array $headers): array
    {
        $cookies = [];
        foreach ($headers as $name => $values) {
            if (\strcasecmp($name, 'cookie') !== 0) {
                continue;
            }

            foreach ($values as $value) {
                foreach (\explode(';', $value) as $pair) {
                    $parts = \explode('=', $pair, 2);
                    if (\count($parts) === 2) {
                        $cookies[\trim($parts[0])] = \trim($parts[1]);
                    }
                }
            }
        }

        return $cookies;
    }

    /**
     * @return list<string>
     */
    private function uploadPaths(Request $request): array
    {
        $paths = [];
        $files = $request->files->all();
        /** @psalm-suppress MixedAssignment */
        $walk = static function (array $files) use (&$walk, &$paths): void {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    if ($file->getError() !== \UPLOAD_ERR_NO_FILE) {
                        $paths[] = $file->getPathname();
                    }
                    continue;
                }

                if (\is_array($file)) {
                    $walk($file);
                }
            }
        };
        $walk($files);

        return $paths;
    }
}
