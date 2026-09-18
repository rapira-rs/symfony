# rapira/symfony

Symfony Runtime integration for [Rapira](https://rapira.rs/) Worker mode.
It boots one Symfony kernel and uses it for sequential requests handled by a
resident Rapira process. The standard Symfony `public/index.php` stays unchanged.

## Installation

The Rapira contract currently has no stable release, so require it explicitly:

```shell
composer require rapira/symfony rapira/contract:dev-master
```

The package requires PHP 8.4 or newer and Symfony 7.4 or 8.0.

## Application setup

This package provides a Rapira-aware Symfony Runtime and runtime autoload
template. Add the following project-level Symfony Runtime configuration to the
application's `composer.json`:

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

Run `composer dump-autoload` after adding it. Composer then generates
`vendor/autoload_runtime.php` with the Runtime class and conventional
`public/index.php` entrypoint baked in. This is important because Rapira exposes
`SCRIPT_FILENAME` only while handling a request, after the worker has already
booted. No `APP_RUNTIME` environment variable or custom bootstrap script is
required. Keep the normal Symfony front controller:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

### Released Rapira 0.8.x

Rapira 0.8.0 and 0.8.1 use the top-level `[pool]` table:

```toml
[http]
listen = "127.0.0.1:8000"

[pool]
entrypoint = "public/index.php"
mode = "worker"
```

### Current unreleased Rapira `main`

Current `main` uses a plugin-scoped pool:

```toml
[http]
listen = "127.0.0.1:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "worker"
```

Start the server with:

```shell
rapira serve rapira.toml
```

## Execution modes

- **Worker**: supported; a single kernel handles requests until Rapira drains the
  worker.
- **Classic**: supported by delegation to Symfony's standard runtime behavior.
- **Dispatcher**: not yet supported for Symfony `HttpKernelInterface`
  applications and fails with a clear exception during startup.

Rapira owns worker recycling, including `max_requests`; this runtime does not
apply a second request limit.

## Persistent state and resets

Worker mode keeps the PHP process, kernel, container, and loaded services alive
between requests. Avoid storing request-specific state in static properties,
singletons, or long-lived services. Symfony's kernel runs its normal next-handle
service reset lifecycle, so services tagged for reset continue to work without
special Rapira integration. Application state outside that lifecycle must be
reset explicitly. The runtime also collects cyclic garbage after each successful
request lifecycle.

Responses use Symfony HttpFoundation's normal `Response::send()` lifecycle. The
Rapira SAPI finalizes the response after the request callback returns; Symfony's
`terminate()` hook then runs before the next request is accepted.
