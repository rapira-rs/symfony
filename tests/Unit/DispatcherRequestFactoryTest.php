<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\InetAddress;
use Rapira\UnixAddress;
use Rapira\Symfony\Internal\DispatcherRequestFactory;
use Rapira\Symfony\Tests\Support\IsolatesProcessState;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class DispatcherRequestFactoryTest
{
    use IsolatesProcessState;

    public function convertsRequestWithoutMutatingGlobals(): void
    {
        $_SERVER = [
            'APP_ENV' => 'test',
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
        ];
        $_GET = ['global' => 'unchanged'];
        $_POST = ['global' => 'unchanged'];
        $_COOKIE = ['global' => 'unchanged'];
        $_FILES = ['global' => 'unchanged'];
        $globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];

        $factory = new DispatcherRequestFactory($_SERVER);
        $converted = $factory->create(self::request(
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
            body: "{\"binary\":\"\\u0000\"}",
        ));
        $request = $converted->request;

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
        Assert::same($request->getContent(), "{\"binary\":\"\\u0000\"}");
        Assert::same($request->server->get('APP_ENV'), 'test');
        Assert::same($request->server->get('APP_CUSTOM_SETTING'), 'kept');
        Assert::same($request->server->get('SCRIPT_FILENAME'), '/app/public/index.php');
        Assert::null($request->headers->get('boot-only'));
        Assert::same([$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES], $globals);
    }

    public function omitsStaleBootRequestMetadataAndKeepsPhysicalListener(): void
    {
        $boot = [
            'APP_ENV' => 'test',
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
        ];
        $factory = new DispatcherRequestFactory($boot);

        $internet = $factory->create(self::request(
            uri: 'http://public.example:9443/plain',
            target: '/plain',
            remote: new UnixAddress(null),
            server: new InetAddress('10.0.0.8', 8443),
        ))->request;
        Assert::same($internet->server->get('SERVER_ADDR'), '10.0.0.8');
        Assert::same($internet->server->get('SERVER_PORT'), 8443);
        Assert::same($internet->server->get('SERVER_PROTOCOL'), 'HTTP/1.1');
        Assert::same($internet->server->get('APP_ENV'), 'test');
        foreach (['AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'HTTPS', 'REMOTE_ADDR', 'REMOTE_PORT', 'REQUEST_SCHEME'] as $key) {
            Assert::false($internet->server->has($key), $key);
        }

        $unix = $factory->create(self::request(
            uri: 'http://localhost/unix',
            target: '/unix',
            remote: new UnixAddress(null),
            server: new UnixAddress('/run/rapira.sock'),
        ))->request;
        Assert::false($unix->server->has('SERVER_ADDR'));
        Assert::false($unix->server->has('SERVER_PORT'));
        Assert::false($unix->server->has('REMOTE_ADDR'));
        Assert::false($unix->server->has('REMOTE_PORT'));
        Assert::false($unix->server->has('HTTPS'));
        Assert::false($unix->server->has('CONTENT_TYPE'));
        Assert::false($unix->server->has('CONTENT_LENGTH'));
    }

    public function preservesUrlEncodedAndMultipartNestedDataAndCleansUploadCopies(): void
    {
        $form = (new DispatcherRequestFactory([]))->create(self::request(
            method: 'POST',
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            body: 'user[name]=Ada&items[]=one&items[]=two',
        ))->request;
        Assert::same($form->request->all(), [
            'user' => ['name' => 'Ada'],
            'items' => ['one', 'two'],
        ]);

        $source = \tempnam(\sys_get_temp_dir(), 'rapira-source-');
        \file_put_contents($source, 'upload-body');
        $multipart = new Multipart(
            [new FormField('meta[name]', 'Ada', [])],
            [
                new RapiraUploadedFile('files[docs][]', 'note.txt', 'text/plain', [], $source, 11),
                new RapiraUploadedFile('empty', '', null, [], $source, 0),
            ],
        );
        $converted = (new DispatcherRequestFactory([]))->create(self::request(
            method: 'POST',
            body: $multipart,
        ));
        $request = $converted->request;

        Assert::same($request->request->all(), ['meta' => ['name' => 'Ada']]);
        $file = $request->files->all()['files']['docs'][0];
        Assert::true($file instanceof UploadedFile);
        Assert::same($file->getClientOriginalName(), 'note.txt');
        Assert::same(\file_get_contents($file->getPathname()), 'upload-body');
        Assert::same($request->files->all()['empty']->getError(), \UPLOAD_ERR_NO_FILE);
        $copy = $file->getPathname();
        Assert::true(\is_file($copy));

        $converted->cleanup();

        Assert::false(\is_file($copy));
        Assert::true(\is_file($source));
        @\unlink($source);
    }

    public function rejectsUnreadableNamedUploadWithoutCreatingTemporaryFiles(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'rapira-source-');
        \file_put_contents($source, 'first');
        $before = self::uploadTempPaths();
        $missing = \sys_get_temp_dir() . '/rapira-missing-' . \bin2hex(\random_bytes(8));
        $multipart = new Multipart([], [
            new RapiraUploadedFile('first', 'first.txt', 'text/plain', [], $source, 5),
            new RapiraUploadedFile('empty', '', null, [], $missing, 0),
            new RapiraUploadedFile('file', 'named.txt', 'text/plain', [], $missing, 10),
        ]);

        Expect::exception(\RuntimeException::class)
            ->withMessage(\sprintf('Unable to read Rapira upload "%s".', $missing));
        try {
            (new DispatcherRequestFactory([]))->create(self::request(body: $multipart));
        } finally {
            Assert::same(self::uploadTempPaths(), $before);
            @\unlink($source);
        }
    }

    public function leavesMovedUploadAliveAfterCleanup(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'rapira-source-');
        \file_put_contents($source, 'keep');
        $multipart = new Multipart([], [
            new RapiraUploadedFile('file', 'file.txt', 'text/plain', [], $source, 4),
        ]);
        $converted = (new DispatcherRequestFactory([]))->create(self::request(body: $multipart));
        $destination = \tempnam(\sys_get_temp_dir(), 'rapira-moved-');
        @\unlink($destination);
        $converted->request->files->get('file')->move(\dirname($destination), \basename($destination));

        $converted->cleanup();

        Assert::same(\file_get_contents($destination), 'keep');
        @\unlink($destination);
        @\unlink($source);
    }

    /**
     * @return list<string>
     */
    private static function uploadTempPaths(): array
    {
        $paths = \glob(\sys_get_temp_dir() . '/rapira-upload-*') ?: [];
        \sort($paths);

        return $paths;
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $uri
     * @param non-empty-string $target
     * @param non-empty-string $protocol
     * @param array<non-empty-string, list<string>> $headers
     * @param InetAddress|UnixAddress $remote
     * @param InetAddress|UnixAddress $server
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
}
