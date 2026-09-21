# rapira/symfony

Symfony Runtime integration for [Rapira](https://rapira.rs/) Worker and Dispatcher modes. It boots one Symfony kernel and uses it for sequential requests handled by a resident Rapira process. Classic mode delegates to Symfony. The standard Symfony `public/index.php` stays unchanged in every mode.

## Installation

The Rapira contract currently has no stable release, so require it explicitly:

```shell
composer require rapira/symfony rapira/contract:dev-master
```

The package requires PHP 8.4 or newer and Symfony 7.4 or 8.0.

## Application setup

Add the Rapira-aware Runtime and autoload template to the application's `composer.json`:

```json
{
    "extra": {
        "runtime": {
            "autoload_template": "vendor/rapira/symfony/src/Internal/autoload_runtime.template",
            "class": "Rapira\\Symfony\\Runtime"
        }
    }
}
```

Run `composer dump-autoload`. Composer generates `vendor/autoload_runtime.php` with the Runtime class and conventional `public/index.php` entrypoint baked in. No `APP_RUNTIME` variable, separate worker script, or custom bootstrap is required:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

### Released Rapira 0.8.x

Rapira 0.8.0 and 0.8.1 use the top-level `[pool]` table. Choose `worker` or `dispatcher`:

```toml
[http]
listen = "127.0.0.1:8000"

[pool]
entrypoint = "public/index.php"
mode = "dispatcher"
```

### Current Rapira `main`

Current `main` uses a plugin-scoped pool:

```toml
[http]
listen = "127.0.0.1:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "dispatcher"
```

Start Rapira with `rapira serve rapira.toml`.

## Execution modes

- **Dispatcher** converts each `Rapira\Http\Exchange` through `rapira/http` and the Symfony PSR-7 bridge, then writes the HttpFoundation response directly to the exchange. Requests are processed sequentially.
- **Worker** uses Rapira's SAPI request loop and Symfony's normal `Response::send()` lifecycle.
- **Classic** delegates to Symfony's standard runtime behavior.

Rapira owns recycling and `max_requests`; this runtime does not apply a second request limit. Dispatcher drain through `ClosedException` exits cleanly. Client cancellation is checked around writes and discarded work does not poison the next request. Unexpected application, adapter, and transport errors propagate so Rapira can recycle the worker.

## Dispatcher semantics

Dispatcher mode does not synthesize request superglobals. `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, `$_SERVER`, `php://input`, `header()`, native PHP sessions, and libraries that require SAPI request globals should not be used for request handling. Use the Symfony `Request`, response headers, and a Symfony-compatible session implementation.

The conversion preserves the exact request target in `REQUEST_URI`, protocol, repeated headers, cookies, body, parsed forms, nested multipart fields and uploads, TLS state, and network addresses. Non-HTTP values present in the boot-time server bag remain available without leaking boot-time `HTTP_*` values.

Multipart uploads are copied to Symfony-owned temporary files. They remain readable through `kernel->terminate()` and are removed after termination. Files moved by application code remain at their destination.

Responses are prepared by HttpFoundation before direct emission. Repeated headers and every `Set-Cookie` value are preserved. Ordinary responses finalize exactly once; streamed and binary responses progressively forward output to Rapira and then send end-of-stream. HEAD, 204, 304, file ranges, 416 responses, temporary files, and `deleteFileAfterSend()` follow Symfony's public `BinaryFileResponse` behavior. Dispatcher mode intentionally does not use `Response::send()`, fibers, or Rapira's zero-copy `sendFile()` yet.

## Persistent state and resets

Worker and Dispatcher modes keep the PHP process, kernel, container, and loaded services alive between requests. Avoid request-specific state in static properties, singletons, or long-lived services. Symfony's next-handle reset lifecycle still applies to services tagged for reset; other state must be reset by the application.

For each successful Dispatcher request the order is handle, response finalization, `kernel->terminate()`, upload cleanup, then cyclic garbage collection. Worker mode finalizes through the SAPI callback before termination. Both modes accept the next request only after the previous lifecycle completes.
