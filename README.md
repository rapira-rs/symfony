# rapira/symfony

Symfony Runtime integration for [Rapira](https://rapira.rs/) Worker and Dispatcher modes. It boots one Symfony kernel and uses it for sequential requests handled by a resident Rapira process. Classic mode delegates to Symfony. The standard Symfony `public/index.php` stays unchanged in every mode.

## Installation

```shell
composer require rapira/symfony
```

The package requires PHP 8.4 or newer and Symfony 7.4 or 8.0. The Rapira server must be version 0.9.0 or newer; Composer installs the PHP contracts but cannot enforce the version of the Rapira binary.

## Application setup

Select the Runtime in the root application's `composer.json`:

```json
{
    "extra": {
        "runtime": {
            "class": "Rapira\\Symfony\\Runtime"
        }
    }
}
```

Run `composer dump-autoload` after changing the Runtime configuration. Symfony generates its stock `vendor/autoload_runtime.php`; no custom autoload template or separate worker entrypoint is required. Keep the standard generated `public/index.php` unchanged:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

Alternatively, leave `extra.runtime.class` unset and export the Runtime when Rapira starts:

```shell
APP_RUNTIME='Rapira\Symfony\Runtime' rapira serve rapira.toml
```

Rapira 0.9.0 imports process environment variables into boot-time `$_SERVER` and `$_ENV`, so Symfony's stock bootstrap sees `APP_RUNTIME`, `APP_ENV`, and `APP_DEBUG`.

Configure Rapira with the current plugin-scoped pool syntax and choose `worker` or `dispatcher`:

```toml
[http]
listen = "127.0.0.1:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "dispatcher"
```

Start Rapira with `rapira serve rapira.toml`.

## Execution modes

- **Dispatcher** converts each `Rapira\Http\Exchange` to a Symfony request and writes the HttpFoundation response directly to the exchange. Requests are processed sequentially.
- **Worker** uses Rapira's SAPI request loop and Symfony's normal `Response::send()` lifecycle.
- **Classic** delegates to Symfony's standard runtime behavior.

Rapira owns recycling and `max_requests`; this runtime does not apply a second request limit. Dispatcher drain through `ClosedException` exits cleanly. Work already cancelled before conversion is skipped. If cancellation happens while emitting a handled response, transport output stops but `kernel->terminate()` still runs; cancellation exceptions thrown by application or termination code are not swallowed. Unexpected application, adapter, termination, and transport programming errors propagate so Rapira can recycle the worker.

## Dispatcher semantics

Dispatcher mode does not synthesize request superglobals. `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, `$_SERVER`, `php://input`, `header()`, native PHP sessions, and libraries that require SAPI request globals should not be used for request handling. Use the Symfony `Request`, response headers, and a Symfony-compatible session implementation.

The conversion preserves the exact request target in `REQUEST_URI`, byte-exact raw method for transport decisions, protocol, repeated headers, cookies, body, parsed forms, nested multipart fields and uploads, TLS state, and network addresses. Live request metadata always wins over the non-HTTP boot-time server defaults, and boot-time `HTTP_*` values are excluded.

Multipart uploads are copied to Symfony-owned temporary files. They remain readable through `kernel->terminate()` and are removed after termination. Files moved by application code remain at their destination.

Responses are prepared by HttpFoundation before direct emission. Repeated headers and every `Set-Cookie` value are preserved. Ordinary responses finalize exactly once; `StreamedResponse`, streamed JSON, and binary responses progressively forward output to Rapira and then send end-of-stream. HEAD retains prepared metadata internally (including binary file length) while Rapira applies its own wire-level HEAD framing; 204, 304, file ranges, 416 responses, temporary files, and `deleteFileAfterSend()` follow Symfony's public behavior. Terminal 100–199 responses other than 101 are rejected because Rapira treats them as interim. Symfony's built-in `EventStreamResponse` is currently rejected because it removes output buffers it does not own; use `StreamedResponse` for server-sent events. Dispatcher mode intentionally does not use `Response::send()`, fibers, or Rapira's zero-copy `sendFile()` yet.

## Persistent state and resets

Worker and Dispatcher modes keep the PHP process, kernel, container, and loaded services alive between requests. Avoid request-specific state in static properties, singletons, or long-lived services. Symfony's next-handle reset lifecycle still applies to services tagged for reset; other state must be reset by the application.

For each successful Dispatcher request the order is handle, response finalization, `kernel->terminate()`, upload cleanup, then cyclic garbage collection. Worker mode finalizes through the SAPI callback before termination. Both modes accept the next request only after the previous lifecycle completes.
