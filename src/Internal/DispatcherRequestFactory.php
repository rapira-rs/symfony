<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
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

    private Psr17Factory $psr17Factory;
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

        $this->psr17Factory = new Psr17Factory();
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function create(RapiraRequest $source): DispatcherRequest
    {
        $psrRequest = $this->createPsrRequest($this->normalizeRequest($source));
        $psrRequest = $psrRequest->withCookieParams($this->parseCookies($source->headers));

        $request = $this->httpFoundationFactory->createRequest($psrRequest);
        $request->server->replace($request->server->all() + $this->server);
        $request->server->set('REQUEST_URI', $source->target);
        $request->server->set('RAPIRA_REQUEST_METHOD', $source->method);
        $request->attributes->set('rapira.request_method', $source->method);

        return new DispatcherRequest($request, $this->uploadPaths($request));
    }

    private function createPsrRequest(RapiraRequest $source): ServerRequestInterface
    {
        $request = $this->psr17Factory->createServerRequest(
            $source->method,
            $source->uri,
            $this->serverParams($source),
        );
        $request = $request->withProtocolVersion(\str_replace('HTTP/', '', $source->protocol));

        foreach ($source->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        $query = [];
        \parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($source->body instanceof Multipart) {
            return $request
                ->withBody($this->psr17Factory->createStream())
                ->withParsedBody($this->parseFields($source->body))
                ->withUploadedFiles($this->createUploadedFiles($source->body));
        }

        $request = $request->withBody($this->psr17Factory->createStream($source->body));
        if (\preg_match('~^application/x-www-form-urlencoded(?:$| |;)~', $request->getHeaderLine('content-type')) === 1) {
            $parsed = [];
            \parse_str($source->body, $parsed);
            $request = $request->withParsedBody($parsed);
        }

        return $request;
    }

    /**
     * @psalm-suppress MissingPureAnnotation
     *
     * @return array<string, mixed>
     */
    private function serverParams(RapiraRequest $request): array
    {
        $params = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->target,
            'SERVER_PROTOCOL' => $request->protocol,
            'REQUEST_TIME' => (int) $request->receivedAt,
            'REQUEST_TIME_FLOAT' => $request->receivedAt,
        ];

        if ($request->authority !== null) {
            $params['HTTP_HOST'] = $request->authority;
        }
        if ($request->tls !== null) {
            $params['HTTPS'] = 'on';
        }
        if ($request->remote instanceof \Rapira\InetAddress) {
            $params['REMOTE_ADDR'] = $request->remote->ip;
            $params['REMOTE_PORT'] = $request->remote->port;
        } elseif ($request->remote->path !== null) {
            $params['REMOTE_ADDR'] = $request->remote->path;
        }
        if ($request->server instanceof \Rapira\InetAddress) {
            $params['SERVER_ADDR'] = $request->server->ip;
            $params['SERVER_PORT'] = $request->server->port;
        } elseif ($request->server->path !== null) {
            $params['SERVER_ADDR'] = $request->server->path;
        }

        foreach ($request->headers as $name => $values) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            if ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
                $key = 'HTTP_' . $key;
            }
            $params[$key] = \implode(', ', $values);
        }

        return $params;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseFields(Multipart $multipart): array
    {
        $pairs = [];
        foreach ($multipart->fields as $field) {
            $pairs[] = \urlencode($field->name) . '=' . \urlencode($field->value);
        }

        $result = [];
        \parse_str(\implode('&', $pairs), $result);

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function createUploadedFiles(Multipart $multipart): array
    {
        $files = [];
        foreach ($multipart->files as $file) {
            $this->addNested($files, $file->name, $this->createUploadedFile($file));
        }

        return $files;
    }

    private function createUploadedFile(RapiraUploadedFile $file): \Psr\Http\Message\UploadedFileInterface
    {
        try {
            $stream = $this->psr17Factory->createStreamFromFile($file->tmpPath);
        } catch (\RuntimeException) {
            $stream = $this->psr17Factory->createStream();
        }

        return $this->psr17Factory->createUploadedFile(
            $stream,
            $file->size,
            $file->clientFilename === '' ? \UPLOAD_ERR_NO_FILE : \UPLOAD_ERR_OK,
            $file->clientFilename,
            $file->clientMediaType,
        );
    }

    /**
     * @psalm-suppress MissingPureAnnotation, MixedAssignment
     *
     * @param array<array-key, mixed> $target
     */
    private function addNested(array &$target, string $name, mixed $value): void
    {
        if (\preg_match('/^([^\[]+)((?:\[[^\]]*])*)$/', $name, $matches) !== 1) {
            $target[$name] = $value;

            return;
        }

        $keys = [$matches[1]];
        if ($matches[2] !== '') {
            \preg_match_all('/\[([^\]]*)]/', $matches[2], $bracketed);
            foreach ($bracketed[1] as $key) {
                $keys[] = $key;
            }
        }

        $this->insert($target, $keys, $value);
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<string> $keys
     *
     * @psalm-suppress MissingPureAnnotation, MixedAssignment, MixedArrayAssignment, MixedArgument
     */
    private function insert(array &$target, array $keys, mixed $value): void
    {
        $key = \array_shift($keys);
        if ($key === null) {
            return;
        }
        if ($key === '') {
            $target[] = $keys === [] ? $value : [];
            if ($keys !== []) {
                /** @var array-key $last */
                $last = \array_key_last($target);
                $this->insert($target[$last], $keys, $value);
            }

            return;
        }
        if ($keys === []) {
            $target[$key] = $value;

            return;
        }
        if (!isset($target[$key]) || !\is_array($target[$key])) {
            $target[$key] = [];
        }
        $this->insert($target[$key], $keys, $value);
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
