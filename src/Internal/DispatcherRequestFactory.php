<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\InetAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Symfony request for an exchange the host hands out in Dispatcher mode.
 *
 * The request is filled the way PHP fills the superglobals in the other modes, so the application sees
 * the same request whatever mode serves it: `$_SERVER`-style meta-variables, query and cookie values
 * decoded and nested by PHP's own rules, form bodies parsed. The superglobals themselves stay untouched.
 *
 * @internal
 */
final readonly class DispatcherRequestFactory
{
    /** @var array<string, mixed> */
    private array $boot;

    public function __construct()
    {
        $this->boot = BootServer::variables($_SERVER, script: false);
    }

    /**
     * @throws \RuntimeException An upload could not be taken over from the host.
     */
    public function create(RapiraRequest $source): DispatcherRequest
    {
        $headers = self::mergeHeaders($source->headers);
        $query = \explode('?', $source->target, 2)[1] ?? '';
        $uploads = [];

        // A body is read by its framing, never by the method name: a form is parsed for any method, where PHP
        // fills `$_POST` and `$_FILES` for `POST` alone.
        try {
            if ($source->body instanceof Multipart) {
                $post = self::parseFields($source->body);
                $files = self::createFiles($source->body, $uploads);
                $content = '';
            } else {
                $post = self::parseForm($source->body, $headers);
                $files = [];
                $content = $source->body;
            }

            $request = new Request(
                query: self::parseQuery($query),
                request: $post,
                cookies: self::parseCookies(\implode('; ', $headers['cookie'][1] ?? [])),
                files: $files,
                server: $this->createServerParams($source, $headers, $query),
                content: $content,
            );
        } catch (\Throwable $exception) {
            DispatcherRequest::removeFiles($uploads);

            throw $exception;
        }

        // The server bag yields one comma-joined value per header; the exchange keeps every value apart.
        foreach ($headers as $lower => [$name, $values]) {
            $request->headers->set($name, $lower === 'cookie' ? \implode('; ', $values) : $values);
        }

        return new DispatcherRequest($request, $uploads);
    }

    /**
     * PHP drops an upload whose name has an unclosed bracket or text after a `]` instead of repairing it
     * as it does for a text field: `a[b`, `a]`, `a[b]c` and `a[[b]]` never reach `$_FILES`.
     *
     * @psalm-pure
     */
    private static function isUploadName(string $name): bool
    {
        $depth = 0;
        for ($i = 0, $length = \strlen($name); $i < $length; ++$i) {
            if ($name[$i] === '[') {
                ++$depth;
            } elseif ($name[$i] === ']') {
                --$depth;
                if ($i + 1 < $length && $name[$i + 1] !== '[') {
                    return false;
                }
            }
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * @param array<non-empty-string, list<string>> $headers
     *
     * @return array<lowercase-string, array{non-empty-string, list<string>}> The values of every spelling
     *         of a name, under the spelling that came first.
     *
     * @psalm-pure
     */
    private static function mergeHeaders(array $headers): array
    {
        $merged = [];
        foreach ($headers as $name => $values) {
            // In Worker mode the host drops a field named outside `[A-Za-z0-9-]` before filling `$_SERVER`,
            // and Symfony reads `_` in a header name as `-`: `X_Forwarded_For` would pose as
            // `X-Forwarded-For` in both the server and the header bag.
            if (\preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1) {
                continue;
            }

            $lower = \strtolower($name);
            if (isset($merged[$lower])) {
                $merged[$lower][1] = [...$merged[$lower][1], ...$values];
            } else {
                $merged[$lower] = [$name, $values];
            }
        }

        return $merged;
    }

    /**
     * @param array<lowercase-string, array{non-empty-string, list<string>}> $headers
     *
     * @return array<array-key, mixed>
     */
    private static function parseForm(string $body, array $headers): array
    {
        // PHP compares the media type case-insensitively, cut at the first `;`, `,` or space.
        $contentType = \implode(', ', $headers['content-type'][1] ?? []);
        if (\preg_match('~^application/x-www-form-urlencoded(?:$|[;, ])~i', $contentType) !== 1) {
            return [];
        }

        return self::parseQuery($body);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parseFields(Multipart $multipart): array
    {
        // Re-encoded as a query string so PHP's own parser builds the `name[key][]` structure and mangles
        // the names exactly as it does for `$_POST`.
        $pairs = [];
        foreach ($multipart->fields as $field) {
            $pairs[] = \rawurlencode($field->name) . '=' . \rawurlencode($field->value);
        }

        return self::parseQuery(\implode('&', $pairs));
    }

    /**
     * @param list<string> $uploads Receives the path of every file taken over, also when this throws.
     *
     * @return array<array-key, mixed>
     */
    private static function createFiles(Multipart $multipart, array &$uploads): array
    {
        // The trick of the fields, so file names nest and mangle the same way: PHP nests the indexes, and
        // each index is then swapped for its file.
        $pairs = [];
        $files = [];
        foreach ($multipart->files as $index => $file) {
            if (!self::isUploadName($file->name)) {
                continue;
            }
            $pairs[] = \rawurlencode($file->name) . '=' . $index;
            $files[$index] = self::createFile($file, $uploads);
        }

        $tree = self::parseQuery(\implode('&', $pairs));
        \array_walk_recursive($tree, static function (mixed &$value) use ($files): void {
            $value = $files[(int) $value];
        });

        return $tree;
    }

    /**
     * @param list<string> $uploads
     * @return UploadedFile|array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private static function createFile(RapiraUploadedFile $file, array &$uploads): UploadedFile|array
    {
        if ($file->clientFilename === '') {
            // FileBag normalizes this to null and removes empty entries from upload lists.
            // Passing null directly is not allowed by FileBag::set().
            return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0];
        }

        // The host deletes its spool file once the exchange finalizes, which is before terminate(); a file
        // renamed away survives that, and the bridge removes it after terminate() instead.
        $path = @\tempnam(\dirname($file->tmpPath), 'rapira-upload-');
        if ($path === false) {
            throw new \RuntimeException(\sprintf('Unable to create a file to keep Rapira upload "%s".', $file->tmpPath));
        }
        $uploads[] = $path;

        if (!@\rename($file->tmpPath, $path)) {
            throw new \RuntimeException(\sprintf('Unable to keep Rapira upload "%s".', $file->tmpPath));
        }

        return new UploadedFile($path, $file->clientFilename, $file->clientMediaType, \UPLOAD_ERR_OK, true);
    }

    /**
     * Parses a `Cookie` header the way PHP fills `$_COOKIE`: `;`-separated pairs with leading whitespace
     * dropped, names mangled and nested by bracket syntax, values raw-URL-decoded so `+` stays `+`, an
     * empty value for a pair without `=`, and the first value kept for a repeated plain name.
     *
     * @return array<array-key, mixed>
     */
    private static function parseCookies(string $header): array
    {
        $pairs = [];
        $seen = [];
        foreach (\explode(';', $header) as $pair) {
            [$name, $value] = \explode('=', \ltrim($pair, " \t\n\r\v\f"), 2) + [1 => ''];
            if ($name === '') {
                continue;
            }

            // The name alone through `parse_str()` gives the key PHP registers it under.
            $probe = self::parseQuery(\rawurlencode($name));
            $key = \array_key_first($probe);
            if ($key === null) {
                continue;
            }

            // PHP drops a repeated plain name, but a bracketed one still nests into the existing key.
            if (!\is_array($probe[$key]) && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Re-encoded so `parse_str()` takes `+` and `&` literally instead of as its own syntax.
            $pairs[] = \rawurlencode($name) . '=' . \rawurlencode(\rawurldecode($value));
        }

        return self::parseQuery(\implode('&', $pairs));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        // Match PHP's startup parsing: input beyond its limits is truncated, not turned into a
        // server error by Symfony's debug error handler. Restore that handler even if parsing fails.
        \set_error_handler(static fn(): bool => true, \E_WARNING);
        try {
            \parse_str($query, $result);
        } finally {
            \restore_error_handler();
        }

        return $result;
    }

    /**
     * @param array<lowercase-string, array{non-empty-string, list<string>}> $headers
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    private function createServerParams(RapiraRequest $request, array $headers, string $query): array
    {
        $https = $request->tls !== null || \str_starts_with($request->uri, 'https:');

        $params = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'Rapira',
            'SERVER_PROTOCOL' => $request->protocol,
            'REQUEST_METHOD' => $request->method,
            'REQUEST_SCHEME' => $https ? 'https' : 'http',
            'REQUEST_URI' => $request->target,
            'DOCUMENT_URI' => \explode('?', $request->target, 2)[0],
            'QUERY_STRING' => $query,
            'REQUEST_TIME' => (int) $request->receivedAt,
            'REQUEST_TIME_FLOAT' => $request->receivedAt,
        ];

        if ($https) {
            $params['HTTPS'] = 'on';
        }

        if ($request->remote instanceof InetAddress) {
            $params['REMOTE_ADDR'] = $request->remote->ip;
            $params['REMOTE_PORT'] = $request->remote->port;
        } else {
            // REMOTE_ADDR must hold a host number (RFC 3875 §4.1.8), so the SAPI puts loopback in for a unix peer.
            $params['REMOTE_ADDR'] = '127.0.0.1';
            $params['REMOTE_PORT'] = 0;
        }

        if ($request->server instanceof InetAddress) {
            $params['SERVER_ADDR'] = $request->server->ip;
            $params['SERVER_PORT'] = $request->server->port;
        }

        // SERVER_NAME is left out: the SAPI sets the configured server name, which a dispatcher does not see, and
        // the authority the client sent must not pass for a value the server controls.

        // Named as the Rapira SAPI names them in Worker mode, the content pair under both names.
        foreach ($headers as $lower => [, $values]) {
            $key = \strtoupper(\str_replace('-', '_', $lower));
            $value = \implode($lower === 'cookie' ? '; ' : ', ', $values);
            $params['HTTP_' . $key] = $value;
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $params[$key] = $value;
            }
        }

        if ($request->authority !== null) {
            $params['HTTP_HOST'] = $request->authority;
        }

        return $params + $this->boot;
    }
}
