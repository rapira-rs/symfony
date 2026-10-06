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
use Symfony\Component\HttpFoundation\Cookie;
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
        Assert::same($request->server->get('REQUEST_METHOD'), 'POST');
        Assert::same($request->server->get('REQUEST_URI'), '/path?q=one&q=two&raw=%2F');
        Assert::same($request->server->get('SERVER_PROTOCOL'), 'HTTP/2');
        Assert::same($request->server->get('REQUEST_TIME'), 1_700_000_000);
        Assert::same($request->server->get('REQUEST_TIME_FLOAT'), 1_700_000_000.5);
        Assert::same($request->server->get('HTTPS'), 'on');
        Assert::same($request->server->get('REQUEST_SCHEME'), 'https');
        Assert::same($request->server->get('SERVER_NAME'), 'example.test');
        Assert::same($request->server->get('QUERY_STRING'), 'q=one&q=two&raw=%2F');
        Assert::same($request->query->all(), ['q' => 'two', 'raw' => '/']);
        Assert::same($request->server->get('REMOTE_ADDR'), '127.0.0.1');
        Assert::same($request->server->get('REMOTE_PORT'), 40000);
        Assert::same($request->server->get('SERVER_ADDR'), '127.0.0.1');
        Assert::same($request->server->get('SERVER_PORT'), 8080);
        Assert::same($request->server->get('CONTENT_TYPE'), 'application/json');
        Assert::same($request->server->get('CONTENT_LENGTH'), '20');
        Assert::same($request->server->get('HTTP_CONTENT_TYPE'), 'application/json');
        Assert::same($request->server->get('HTTP_CONTENT_LENGTH'), '20');
        Assert::same($request->headers->all('x-repeat'), ['one', 'two']);
        Assert::same($request->cookies->all(), ['a' => '1', 'b' => '2']);
        Assert::same($request->getContent(), '{"binary":"\u0000"}');
        Assert::same($request->server->get('APP_ENV'), 'test');
        Assert::same($request->server->get('APP_CUSTOM_SETTING'), 'kept');
        Assert::false($request->server->has('SCRIPT_FILENAME'));
        Assert::null($request->headers->get('boot-only'));
        Assert::same([$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES], $globals);
    }

    /**
     * Rapira serves from the root, with no document root or script to locate: the base URL Symfony
     * derives from the script location of the boot would only cut a prefix off the path.
     */
    public function scriptLocationOfTheBootIsLeftOutSoUrlsAreRootRelative(): void
    {
        $_SERVER = [
            'DOCUMENT_ROOT' => '/app/public',
            'ORIG_SCRIPT_NAME' => '/index.php',
            'PATH_TRANSLATED' => '/app/public/index.php',
            'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => '/app/public/index.php',
            'SCRIPT_NAME' => '/index.php',
        ] + $_SERVER;
        $kernel = new TestKernel();

        $this->serve($kernel, FakeExchange::for('/blog/post?page=2'), FakeExchange::for('/index.php/blog'));

        [$path, $script] = $kernel->requests;
        foreach (['DOCUMENT_ROOT', 'ORIG_SCRIPT_NAME', 'PATH_TRANSLATED', 'PHP_SELF', 'SCRIPT_FILENAME', 'SCRIPT_NAME'] as $key) {
            Assert::false($path->server->has($key), $key);
        }
        Assert::same($path->getBaseUrl(), '');
        Assert::same($path->getBasePath(), '');
        Assert::same($path->getPathInfo(), '/blog/post');
        Assert::same($path->getUriForPath('/feed'), 'http://localhost:8080/feed');
        Assert::same($path->getUri(), 'http://localhost:8080/blog/post?page=2');
        Assert::same($script->getBaseUrl(), '');
        Assert::same($script->getPathInfo(), '/index.php/blog');
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
        Assert::same($internet->server->get('REQUEST_SCHEME'), 'http');
        Assert::same($internet->server->get('SERVER_NAME'), 'public.example');
        foreach (['AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'HTTPS', 'REMOTE_ADDR', 'REMOTE_PORT'] as $key) {
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

    public function urlEncodedFormIsParsedWhateverTheContentTypeCase(): void
    {
        $request = $this->serveOne(FakeExchange::for(
            '/form',
            'POST',
            ['content-type' => ['Application/X-WWW-Form-Urlencoded']],
            'user[name]=Ada',
        ));

        Assert::same($request->request->all(), ['user' => ['name' => 'Ada']]);
    }

    public function formMediaTypeIsCutAtTheFirstSemicolonCommaOrSpace(): void
    {
        $kernel = new TestKernel();
        $form = static fn(string $type, string $body): FakeExchange => FakeExchange::for('/', 'POST', ['content-type' => [$type]], $body);

        $this->serve(
            $kernel,
            $form('application/x-www-form-urlencoded;charset=UTF-8', 'a=1'),
            $form('application/x-www-form-urlencoded,text/plain', 'a=2'),
            $form('application/x-www-form-urlencoded text', 'a=3'),
            $form('application/x-www-form-urlencodedx', 'a=4'),
        );

        Assert::same(\array_map(static fn(Request $request): array => $request->request->all(), $kernel->requests), [
            ['a' => '1'],
            ['a' => '2'],
            ['a' => '3'],
            [],
        ]);
    }

    public function multipartBodyIsParsedWhateverTheMethod(): void
    {
        $kernel = new TestKernel();
        $sources = [];
        $exchanges = [];
        foreach (['GET', 'QUERY', 'post'] as $method) {
            $sources[] = $source = self::file($method);
            $exchanges[] = FakeExchange::for('/', $method, body: new Multipart(
                [new FormField('field', $method, [])],
                [new RapiraUploadedFile('file', 'a.txt', 'text/plain', [], $source, \strlen($method))],
            ));
        }

        try {
            $this->serve($kernel, ...$exchanges);
        } finally {
            foreach ($sources as $source) {
                @\unlink($source);
            }
        }

        foreach (['GET', 'QUERY', 'post'] as $i => $method) {
            Assert::same($kernel->requests[$i]->request->all(), ['field' => $method]);
            Assert::same(\array_keys($kernel->requests[$i]->files->all()), ['file']);
        }
    }

    public function uploadWithABrokenBracketNameIsDroppedWhereAFieldIsRepaired(): void
    {
        $kernel = new TestKernel();
        $sources = [];
        $files = [];
        foreach (['u[v', '[m]', 'a[b]c', 'a[[b]]', 'kept[x]'] as $name) {
            $sources[] = $source = self::file($name);
            $files[] = new RapiraUploadedFile($name, 'f.txt', 'text/plain', [], $source, \strlen($name));
        }

        try {
            $this->serve($kernel, FakeExchange::for('/', 'POST', body: new Multipart([new FormField('u[v', 'text', [])], $files)));
        } finally {
            foreach ($sources as $source) {
                @\unlink($source);
            }
        }

        Assert::same($kernel->requests[0]->request->all(), ['u_v' => 'text']);
        Assert::same(\array_keys($kernel->requests[0]->files->all()), ['kept']);
        Assert::same(\array_keys($kernel->requests[0]->files->all()['kept']), ['x']);
    }

    public function headerNamedOutsideTheTokenSetCannotPoseAsAnotherHeader(): void
    {
        $request = $this->serveOne(FakeExchange::for('/', headers: [
            'X_Forwarded_For' => ['203.0.113.1'],
            'X.Forwarded.For' => ['203.0.113.2'],
            'X-Real' => ['real'],
        ]));

        Assert::false($request->server->has('HTTP_X_FORWARDED_FOR'));
        Assert::null($request->headers->get('x-forwarded-for'));
        Assert::same($request->server->get('HTTP_X_REAL'), 'real');
        Assert::same($request->headers->get('x-real'), 'real');
    }

    public function serverNameIsTheAuthorityHostWithoutPortOrBrackets(): void
    {
        $request = $this->serveOne(new FakeExchange(self::request(uri: 'https://[::1]:8443/', target: '/')));

        Assert::same($request->server->get('SERVER_NAME'), '::1');
        Assert::same($request->server->get('HTTPS'), 'on');
        Assert::same($request->server->get('REQUEST_SCHEME'), 'https');
    }

    public function cookiesFollowPhpForPlusSignsBareNamesAndMangledDuplicates(): void
    {
        $request = $this->serveOne(FakeExchange::for('/', headers: [
            'cookie' => ['plus=a+b; bare; a.b=first; a_b=second; list[]=1; list[]=2'],
        ]));

        Assert::same($request->cookies->all(), [
            'plus' => 'a+b',
            'bare' => '',
            'a_b' => 'first',
            'list' => ['1', '2'],
        ]);
    }

    public function multipartFieldsAndFilesKeepTheirNestingAndUploadsAreRemovedAfterwards(): void
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
        Assert::false(\is_file($source));
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
            Assert::same($exception->getMessage(), \sprintf('Unable to keep Rapira upload "%s".', $missing));
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
     * The host deletes its spool file when the exchange finalizes, which happens before terminate().
     */
    public function uploadOutlivesTheHostSpoolFileUntilTerminate(): void
    {
        $source = self::file('late');
        $read = null;
        $kernel = new TestKernel(terminate: static function (Request $request) use ($source, &$read): void {
            @\unlink($source);
            $read = \file_get_contents($request->files->get('file')->getPathname());
        });

        $this->serve($kernel, FakeExchange::for('/upload', 'POST', body: new Multipart([], [
            new RapiraUploadedFile('file', 'file.txt', 'text/plain', [], $source, 4),
        ])));

        Assert::same($read, 'late');
    }

    public function multipartFileNamesAreMangledLikeFieldNames(): void
    {
        $source = self::file('body');
        $kernel = new TestKernel();

        $this->serve($kernel, FakeExchange::for('/upload', 'POST', body: new Multipart(
            [new FormField('field.name', 'value', []), new FormField('list one[]', 'item', [])],
            [
                new RapiraUploadedFile('file.name', 'a.txt', 'text/plain', [], $source, 4),
                new RapiraUploadedFile('list two[]', '', null, [], $source, 0),
            ],
        )));

        $request = $kernel->requests[0];
        Assert::same($request->request->all(), ['field_name' => 'value', 'list_one' => ['item']]);
        Assert::same(\array_keys($request->files->all()), ['file_name', 'list_two']);
        Assert::true($request->files->all()['list_two'][0] instanceof UploadedFile);
    }

    public function urlEncodedBodyIsParsedWhateverTheMethod(): void
    {
        $form = ['content-type' => ['application/x-www-form-urlencoded']];
        $kernel = new TestKernel();

        $this->serve(
            $kernel,
            FakeExchange::for('/', 'PATCH', $form, 'a=1'),
            FakeExchange::for('/', 'GET', $form, 'a=2'),
            FakeExchange::for('/', 'QUERY', $form, 'a=3'),
            FakeExchange::for('/', 'patch', $form, 'a=4'),
            FakeExchange::for('/', 'GET', ['content-type' => ['text/plain']], 'a=5'),
        );

        Assert::same(\array_map(static fn(Request $request): array => $request->request->all(), $kernel->requests), [
            ['a' => '1'],
            ['a' => '2'],
            ['a' => '3'],
            ['a' => '4'],
            [],
        ]);
        Assert::same($kernel->requests[1]->getContent(), 'a=2');
    }

    public function cookieValuesAreUrlDecoded(): void
    {
        $request = $this->serveOne(FakeExchange::for('/', headers: ['cookie' => ['sid=a%3Ab%3D; plain=a%20b']]));

        Assert::same($request->cookies->all(), ['sid' => 'a:b=', 'plain' => 'a b']);
    }

    public function firstOfDuplicateCookieNamesWins(): void
    {
        $request = $this->serveOne(FakeExchange::for('/', headers: ['cookie' => ['a=1; a=2', 'a=3']]));

        Assert::same($request->cookies->get('a'), '1');
    }

    public function severalCookieHeadersAreJoinedAsOneCookieList(): void
    {
        $request = $this->serveOne(FakeExchange::for('/', headers: ['cookie' => ['a=1', 'b=2']]));

        Assert::same($request->cookies->all(), ['a' => '1', 'b' => '2']);
        Assert::same($request->headers->get('cookie'), 'a=1; b=2');
        Assert::same($request->server->get('HTTP_COOKIE'), 'a=1; b=2');
    }

    public function cookieSetBySymfonyRoundTrips(): void
    {
        $value = 'a:b= c;d/é';
        $set = FakeExchange::for('/set');
        $this->serve(new TestKernel(static function () use ($value): Response {
            $response = new Response();
            $response->headers->setCookie(new Cookie('sid', $value));

            return $response;
        }), $set);
        FakeRuntime::reset();
        $cookie = \explode(';', $set->headers['Set-Cookie'][0], 2)[0];

        $request = $this->serveOne(FakeExchange::for('/read', headers: ['cookie' => [$cookie]]));

        Assert::same($request->cookies->get('sid'), $value);
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
