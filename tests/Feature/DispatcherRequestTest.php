<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\InetAddress;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Rapira\Symfony\Tests\Support\FakeRuntimeLifecycle;
use Rapira\Symfony\Tests\Support\TestKernel;
use Rapira\UnixAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;
use Testo\Test;

/**
 * The Symfony request an application sees for an exchange in {@see Mode::Dispatcher}.
 */
#[Test]
final class DispatcherRequestTest
{
    use FakeRuntimeLifecycle;

    public function requestDataReachesTheApplicationWithoutTouchingTheGlobals(): void
    {
        $_SERVER = [
            'APP_CUSTOM_SETTING' => 'kept',
            'SCRIPT_FILENAME' => '/app/public/index.php',
            'HTTP_BOOT_ONLY' => 'no',
            'REQUEST_METHOD' => 'BOOT',
            'REQUEST_URI' => '/boot',
            'SERVER_PROTOCOL' => 'HTTP/0.9',
            'REQUEST_TIME' => 1,
            'REQUEST_TIME_FLOAT' => 1.5,
            'HTTPS' => 'off',
            'REMOTE_ADDR' => '192.0.2.1',
            'REMOTE_PORT' => 1,
            'SERVER_ADDR' => '192.0.2.2',
            'SERVER_PORT' => 2,
            'CONTENT_TYPE' => 'text/boot',
            'CONTENT_LENGTH' => '999',
        ] + $_SERVER;
        $_GET = ['global' => 'unchanged'];
        $_POST = ['global' => 'unchanged'];
        $_COOKIE = ['global' => 'unchanged'];
        $_FILES = ['global' => 'unchanged'];
        $exchange = new FakeExchange(self::request(
            method: 'POST',
            uri: 'https://example.test/path?q=one&q=two',
            target: '/path?q=one&q=two&raw=%2F',
            protocol: 'HTTP/2',
            headers: [
                'Host' => ['example.test'],
                'X-Repeat' => ['one'],
                'x-repeat' => ['two'],
                'Cookie' => ['a=1'],
                'cookie' => ['b=2'],
                'Content-Type' => ['application/json'],
                'Content-Length' => ['20'],
            ],
            body: '{"binary":"\u0000"}',
        ));

        [$request, $globals] = $this->serveWithGlobals($exchange);

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->server->get('RAPIRA_REQUEST_METHOD'), 'POST');
        Assert::same($request->attributes->get('rapira.request_method'), 'POST');
        Assert::same($request->server->get('REQUEST_URI'), '/path?q=one&q=two&raw=%2F');
        Assert::same($request->server->get('SERVER_PROTOCOL'), 'HTTP/2');
        Assert::same($request->server->get('REQUEST_TIME'), 1_700_000_000);
        Assert::same($request->server->get('REQUEST_TIME_FLOAT'), 1_700_000_000.5);
        Assert::same($request->server->get('HTTPS'), 'on');
        Assert::same($request->server->get('REMOTE_ADDR'), '127.0.0.1');
        Assert::same($request->server->get('REMOTE_PORT'), 40000);
        Assert::same($request->server->get('SERVER_ADDR'), '127.0.0.1');
        Assert::same($request->server->get('SERVER_PORT'), 8080);
        Assert::same($request->server->get('CONTENT_TYPE'), 'application/json');
        Assert::same($request->server->get('CONTENT_LENGTH'), '20');
        Assert::same($request->headers->all('x-repeat'), ['one', 'two']);
        Assert::same($request->cookies->all(), ['a' => '1', 'b' => '2']);
        Assert::same($request->getContent(), '{"binary":"\u0000"}');
        Assert::same($request->server->get('APP_ENV'), 'test');
        Assert::same($request->server->get('APP_CUSTOM_SETTING'), 'kept');
        Assert::same($request->server->get('SCRIPT_FILENAME'), '/app/public/index.php');
        Assert::null($request->headers->get('boot-only'));
        Assert::same([$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES], $globals);
    }

    public function requestMetadataOfTheBootIsNotInheritedAndTheListenerIsKept(): void
    {
        $_SERVER = [
            'AUTH_TYPE' => 'Basic',
            'CONTENT_LENGTH' => '999',
            'CONTENT_TYPE' => 'text/boot',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.1',
            'REMOTE_PORT' => 1,
            'REQUEST_SCHEME' => 'https',
            'SERVER_ADDR' => '192.0.2.2',
            'SERVER_PORT' => 2,
            'SERVER_PROTOCOL' => 'HTTP/0.9',
        ] + $_SERVER;
        $kernel = new TestKernel();

        $this->serve($kernel, new FakeExchange(self::request(
            uri: 'http://public.example:9443/plain',
            target: '/plain',
            remote: new UnixAddress(null),
            server: new InetAddress('10.0.0.8', 8443),
        )), new FakeExchange(self::request(
            uri: 'http://localhost/unix',
            target: '/unix',
            remote: new UnixAddress(null),
            server: new UnixAddress('/run/rapira.sock'),
        )));

        [$internet, $unix] = $kernel->requests;
        Assert::same($internet->server->get('SERVER_ADDR'), '10.0.0.8');
        Assert::same($internet->server->get('SERVER_PORT'), 8443);
        Assert::same($internet->server->get('SERVER_PROTOCOL'), 'HTTP/1.1');
        Assert::same($internet->server->get('APP_ENV'), 'test');
        foreach (['AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'HTTPS', 'REMOTE_ADDR', 'REMOTE_PORT', 'REQUEST_SCHEME'] as $key) {
            Assert::false($internet->server->has($key), $key);
        }
        foreach (['SERVER_ADDR', 'SERVER_PORT', 'REMOTE_ADDR', 'REMOTE_PORT', 'HTTPS', 'CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            Assert::false($unix->server->has($key), $key);
        }
    }

    public function urlEncodedFormIsParsedIntoNestedFields(): void
    {
        $request = $this->serveOne(FakeExchange::for(
            '/form',
            'POST',
            ['content-type' => ['application/x-www-form-urlencoded']],
            'user[name]=Ada&items[]=one&items[]=two',
        ));

        Assert::same($request->request->all(), [
            'user' => ['name' => 'Ada'],
            'items' => ['one', 'two'],
        ]);
    }

    public function multipartFieldsAndFilesKeepTheirNestingAndUploadCopiesAreRemovedAfterwards(): void
    {
        $source = self::file('upload-body');
        $seen = [];
        $kernel = new TestKernel(static function (Request $request) use (&$seen): Response {
            $file = $request->files->all()['files']['docs'][0];
            Assert::true($file instanceof UploadedFile);
            $seen = [
                'fields' => $request->request->all(),
                'name' => $file->getClientOriginalName(),
                'content' => \file_get_contents($file->getPathname()),
                'path' => $file->getPathname(),
                'empty' => $request->files->all()['empty']->getError(),
            ];

            return new Response();
        });

        $this->serve($kernel, FakeExchange::for('/upload', 'POST', body: new Multipart(
            [new FormField('meta[name]', 'Ada', [])],
            [
                new RapiraUploadedFile('files[docs][]', 'note.txt', 'text/plain', [], $source, 11),
                new RapiraUploadedFile('empty', '', null, [], $source, 0),
            ],
        )));

        Assert::same($seen['fields'], ['meta' => ['name' => 'Ada']]);
        Assert::same($seen['name'], 'note.txt');
        Assert::same($seen['content'], 'upload-body');
        Assert::same($seen['empty'], \UPLOAD_ERR_NO_FILE);
        Assert::false(\is_file($seen['path']));
        Assert::true(\is_file($source));
        @\unlink($source);
    }

    /**
     * Holds whether the failure stops the loop or is answered.
     */
    public function unreadableUploadLeavesNoTemporaryCopies(): void
    {
        $source = self::file('first');
        $before = self::uploadCopies();
        $missing = \sys_get_temp_dir() . '/rapira-missing-' . \bin2hex(\random_bytes(8));
        $exchange = FakeExchange::for('/upload', 'POST', body: new Multipart([], [
            new RapiraUploadedFile('first', 'first.txt', 'text/plain', [], $source, 5),
            new RapiraUploadedFile('empty', '', null, [], $missing, 0),
            new RapiraUploadedFile('file', 'named.txt', 'text/plain', [], $missing, 10),
        ]));
        $kernel = new TestKernel();

        try {
            $this->serve($kernel, $exchange);
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), \sprintf('Unable to read Rapira upload "%s".', $missing));
        } finally {
            @\unlink($source);
        }

        Assert::same($kernel->requests, []);
        Assert::same(self::uploadCopies(), $before);
    }

    public function uploadMovedByTheApplicationSurvivesTheCleanup(): void
    {
        $source = self::file('keep');
        $destination = \tempnam(\sys_get_temp_dir(), 'rapira-moved-');
        @\unlink($destination);
        $kernel = new TestKernel(static function (Request $request) use ($destination): Response {
            $request->files->get('file')->move(\dirname($destination), \basename($destination));

            return new Response();
        });

        $this->serve($kernel, FakeExchange::for('/upload', 'POST', body: new Multipart([], [
            new RapiraUploadedFile('file', 'file.txt', 'text/plain', [], $source, 4),
        ])));

        Assert::same(\file_get_contents($destination), 'keep');
        @\unlink($destination);
        @\unlink($source);
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $uri
     * @param non-empty-string $target
     * @param non-empty-string $protocol
     * @param array<non-empty-string, list<string>> $headers
     */
    private static function request(
        string $method = 'GET',
        string $uri = 'http://localhost/',
        string $target = '/',
        string $protocol = 'HTTP/1.1',
        array $headers = [],
        string|Multipart $body = '',
        InetAddress|UnixAddress|null $remote = null,
        InetAddress|UnixAddress|null $server = null,
    ): RapiraRequest {
        return new RapiraRequest(
            $method,
            $uri,
            $target,
            null,
            $protocol,
            $headers,
            $body,
            $remote ?? new InetAddress('127.0.0.1', 40000),
            $server ?? new InetAddress('127.0.0.1', 8080),
            null,
            1_700_000_000.5,
        );
    }

    private static function file(string $content): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rapira-source-');
        \file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function uploadCopies(): array
    {
        $paths = \glob(\sys_get_temp_dir() . '/rapira-upload-*') ?: [];
        \sort($paths);

        return $paths;
    }

    /**
     * Serves one exchange and returns the request the application saw, with the superglobals as they were
     * just before the loop started.
     *
     * @return array{Request, list<array<array-key, mixed>>}
     */
    private function serveWithGlobals(FakeExchange $exchange): array
    {
        $kernel = new TestKernel();
        (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher($exchange)))->install();
        $runner = self::runtime()->getRunner($kernel);
        $globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];

        $runner->run();

        return [$kernel->requests[0], $globals];
    }

    private function serveOne(FakeExchange $exchange): Request
    {
        $kernel = new TestKernel();
        $this->serve($kernel, $exchange);

        return $kernel->requests[0];
    }

    private function serve(TestKernel $kernel, FakeExchange ...$exchanges): void
    {
        (new FakeRuntime(Mode::Dispatcher, new FakeHttpDispatcher(...$exchanges)))->install();

        self::runtime()->getRunner($kernel)->run();
    }
}
