<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Unit;

use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\InetAddress;
use Rapira\Symfony\Internal\DispatcherRequestFactory;
use Rapira\Symfony\Tests\Support\StubExchange;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Testo\Assert;
use Testo\Test;

#[Test]
final class DispatcherRequestFactoryTest
{
    public function convertsRequestWithoutMutatingGlobals(): void
    {
        $_SERVER = ['APP_ENV' => 'test', 'HTTP_BOOT_ONLY' => 'no'];
        $_GET = ['global' => 'unchanged'];
        $_POST = ['global' => 'unchanged'];
        $_COOKIE = ['global' => 'unchanged'];
        $_FILES = ['global' => 'unchanged'];
        $globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];

        $factory = new DispatcherRequestFactory($_SERVER);
        $converted = $factory->create(new StubExchange(self::request(
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
            ],
            body: "{\"binary\":\"\\u0000\"}",
        )));
        $request = $converted->request;

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->server->get('REQUEST_URI'), '/path?q=one&q=two&raw=%2F');
        Assert::same($request->server->get('SERVER_PROTOCOL'), 'HTTP/2');
        Assert::same($request->headers->all('x-repeat'), ['one', 'two']);
        Assert::same($request->cookies->all(), ['a' => '1', 'b' => '2']);
        Assert::same($request->getContent(), "{\"binary\":\"\\u0000\"}");
        Assert::same($request->server->get('APP_ENV'), 'test');
        Assert::null($request->headers->get('boot-only'));
        Assert::same([$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES], $globals);
    }

    public function preservesUrlEncodedAndMultipartNestedDataAndCleansUploadCopies(): void
    {
        $form = (new DispatcherRequestFactory([]))->create(new StubExchange(self::request(
            method: 'POST',
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            body: 'user[name]=Ada&items[]=one&items[]=two',
        )))->request;
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
        $converted = (new DispatcherRequestFactory([]))->create(new StubExchange(self::request(
            method: 'POST',
            body: $multipart,
        )));
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

    public function leavesMovedUploadAliveAfterCleanup(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'rapira-source-');
        \file_put_contents($source, 'keep');
        $multipart = new Multipart([], [
            new RapiraUploadedFile('file', 'file.txt', 'text/plain', [], $source, 4),
        ]);
        $converted = (new DispatcherRequestFactory([]))->create(new StubExchange(self::request(body: $multipart)));
        $destination = \tempnam(\sys_get_temp_dir(), 'rapira-moved-');
        @\unlink($destination);
        $converted->request->files->get('file')->move(\dirname($destination), \basename($destination));

        $converted->cleanup();

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
    ): RapiraRequest {
        return new RapiraRequest(
            $method,
            $uri,
            $target,
            null,
            $protocol,
            $headers,
            $body,
            new InetAddress('127.0.0.1', 40000),
            new InetAddress('127.0.0.1', 8080),
            null,
            1_700_000_000.5,
        );
    }
}
