# rapira/symfony

Symfony Runtime for [Rapira](https://rapira.rs/). The same `public/index.php` serves a Symfony HTTP kernel in every Rapira mode: Classic boots it per request, Worker and Dispatcher keep one kernel alive and serve requests one after another.

## Installation

```shell
composer require rapira/symfony
```

Requires PHP 8.4 or newer, the Symfony components `http-foundation`, `http-kernel` and `runtime` in versions `^7.4 || ^8.0`, and Rapira 0.9.1 or newer: the x86_64 Linux builds of 0.9.0 crash a Dispatcher worker that checks whether its exchange is cancelled. Composer installs the PHP contract but cannot check the version of the Rapira binary.

## Application setup

Select the Runtime in the application's `composer.json` and run `composer dump-autoload`:

```json
{
    "extra": {
        "runtime": {
            "class": "Rapira\\Symfony\\Runtime"
        }
    }
}
```

Keep the stock `public/index.php`:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

Alternatively, leave `extra.runtime.class` unset and export `APP_RUNTIME='Rapira\Symfony\Runtime'` when Rapira starts. Rapira imports the process environment into the boot-time `$_SERVER`, where Symfony looks for `APP_RUNTIME`, `APP_ENV` and `APP_DEBUG`.

Choose the mode in `rapira.toml` and start the server with `rapira serve rapira.toml`:

```toml
[http]
listen = "127.0.0.1:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "dispatcher" # or "worker", "classic"
```

The same setup stays valid outside Rapira: under php-fpm or the CLI the Runtime behaves as Symfony's own.

## Execution modes

- **Classic** sends the response, calls `rapira_finish_request()` so the client has its answer, then runs `kernel->terminate()`.
- **Worker** loops over `Rapira\handle_request()`. Per request: the request is built from the superglobals, the response is sent and finished, then terminate runs inside the request handler while uploads and the execution timeout remain available. Cyclic garbage is collected after the handler returns.
- **Dispatcher** takes each `Rapira\Http\Exchange` from the HTTP dispatcher, converts it to a Symfony `Request` and writes the response straight into the exchange. Exchanges are served one at a time; one cancelled while it was queued is skipped.

Worker and Dispatcher serve only an `HttpKernelInterface` application and refuse anything else with a `LogicException`. Rapira owns recycling and `max_requests`; the Runtime adds no request limit of its own.

In Classic and Worker modes the response is sent inside an output buffer of its own, flushed every 8 KiB and on every `ob_flush()`, as under php-fpm with `output_buffering`. The host builds a Worker request's `$_SERVER` from the request alone, so the Runtime adds the boot-time variables back, `APP_ENV`, `APP_SECRET` and the rest of the environment, but never boot-time request metadata such as `HTTP_*`, `REMOTE_ADDR` or `HTTPS`. Application variables such as `SERVER_ROLE` and `REQUEST_TIMEOUT` are preserved.

## Failures

In Worker and Dispatcher modes a Symfony kernel boots before the first request, so a broken container or bundle fails the worker before it takes any work, and Rapira counts it as a failed start. In debug too: such a failure shows in Rapira's log, not in the browser.

The kernel answers the errors of a request itself. A failure that escapes it, such as an exception thrown by a `kernel.exception` listener, leaves the kernel in an unknown state. The request is answered with a plain `500`, the failure is logged through `Rapira\log()`, and the loop ends with exit code `1`. Rapira then runs the script again in a fresh request, booting a new kernel. A failure of `kernel->terminate()` ends the loop the same way, after the client has its answer.

To answer such a failure with an error page, extend the Runtime, override `createFailureResponse()` and name the subclass in `extra.runtime.class` of `composer.json`. If it throws, or its response cannot be sent, the client gets the plain `500`.

In Classic mode failures are left to Symfony's error handler, as under php-fpm.

## Dispatcher is sequential for Symfony

Dispatcher mode is built for code that serves exchanges concurrently, which rules out per-request superglobals, mutable static state and `header()`. Symfony still serves them one at a time here: a `StreamedResponse` prints its body, so it can only be captured through PHP's process-wide output buffers, and the framework keeps request-related state in statics such as the trusted proxies of `Request` and in services shared by the whole process. An application, or a library, that needs the superglobals or `header()` belongs in Worker mode.

## Dispatcher requests

The superglobals are neither read nor filled. The Symfony `Request` holds what the Rapira SAPI and PHP would have put there in Worker mode: the request target as sent in `REQUEST_URI` and `QUERY_STRING`, the method byte for byte in `REQUEST_METHOD`, every header value, query and cookie values decoded and nested by PHP's rules, form and multipart bodies parsed by their `Content-Type` for any method, with PHP's name mangling, TLS state and both network addresses, a unix peer as `127.0.0.1`. `SERVER_NAME` is left unset: the SAPI takes it from `[http] server_name`, which a dispatcher does not see, and the client-sent authority stays in `HTTP_HOST`. A header named outside `[A-Za-z0-9-]` is dropped, as the host drops it in Worker mode, so `X_Forwarded_For` cannot pose as `X-Forwarded-For`. The boot-time environment is added as in Worker mode, but not the script location: Rapira serves from the root, so the base URL is empty and the whole path is the path info.

Code that reads `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER` or `php://input`, or answers through `header()`, does not see the request. Symfony sessions work, `NativeSessionStorage` included: the session listener takes the session id from the request's cookies and sets the session cookie on the response. Calling `session_start()` directly does not, since PHP looks for the id in `$_COOKIE` and sends its cookie through `header()`.

Uploaded files are renamed out of Rapira's spool, which the host empties once the response is sent, so they stay readable through `kernel->terminate()`; the Runtime removes them afterwards. A file the application moved stays where it was moved. Empty upload inputs are normalized by Symfony's `FileBag`, just as in Classic and Worker modes.

Input exceeding PHP's parsing limits (such as `max_input_vars`) is truncated as in PHP's startup parsing, including when Symfony's debug error handler is enabled.

## Dispatcher responses

Responses are prepared by HttpFoundation, with every repeated header and `Set-Cookie` value kept.

- Output the application prints while handling the request goes out ahead of the body, except ahead of a file.
- A buffered body goes out with the head in one write, or in 8 MiB writes under its length when longer.
- A `BinaryFileResponse` is handed to Rapira with `Exchange::sendFile()`, ranges included, so PHP never holds the bytes. A temporary file, a file deleted after sending and a file Rapira refuses are streamed by PHP instead. The same fallback applies if a Symfony version changes the internal properties needed for the optimization.
- A `StreamedResponse`, or anything else that prints its body, is captured and forwarded every 8 KiB and on every `ob_flush()`. A bare `flush()` reaches no output handler, so call `ob_flush()` before it where latency matters. The head goes out with the first chunk.
- `HEAD`, `204` and `304` send no body. A `HEAD` request for a file does not read it, and `deleteFileAfterSend()` still applies.
- If the client leaves mid-stream, the rest of the output is dropped and terminate still runs. The streaming code is not told: `connection_aborted()` does not reflect the exchange.
- `EventStreamResponse` is answered with `500`: it closes every output buffer after each event, so nothing is left to capture the next one. Use a `StreamedResponse` that calls `ob_flush()` after each event.

A request that cannot be converted, and a response that fails before its head is written, such as a terminal `1xx` status or a header value the wire cannot carry, are logged through `Rapira\log()` and answered with `400` or `500`, and the worker goes on: the kernel was not involved. A response that fails after its head is out cannot be answered any more; it is logged, Rapira fails the unfinished exchange, and termination runs before the worker serves the next request. A damaged output buffer stack still ends the loop because it cannot be reused safely.

## Persistent state

In Worker and Dispatcher modes the process, kernel, container and services outlive each request. Keep request state out of static properties and long-lived services; Symfony resets services tagged `kernel.reset` between requests, anything else is the application's to reset.

The kernel boots once, before the first request, and `Kernel::boot()` resolves the `REMOTE_ADDR` placeholder of `trusted_proxies` from `$_SERVER` at that moment. The boot-time `$_SERVER` holds no client address, so the placeholder is dropped and trusts no proxy for the life of the worker. List the proxy addresses or subnets explicitly, or use `PRIVATE_SUBNETS`, instead of `REMOTE_ADDR`.

## Development

The feature and live-server acceptance suites use Testo:

```sh
composer install
composer test
composer test:feature
composer test:acceptance
```

The acceptance suite downloads Rapira and its bundled PHP into `runtime/bin` when missing. The `rapira/testing` Testo integration starts a fixture app in Classic, Worker and Dispatcher modes and stops each server after its test case. The first run needs network access to download the binary; acceptance tests need permission to bind local ports.
