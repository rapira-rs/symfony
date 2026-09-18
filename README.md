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

Symfony's Runtime component reads `APP_RUNTIME` before it loads
`vendor/autoload_runtime.php`. Define it in the process environment; setting it
inside `public/index.php` after the runtime autoloader is required is too late.

```dotenv
APP_RUNTIME=Rapira\Symfony\Runtime
```

Keep the normal Symfony front controller:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

### Rapira 0.8.x

```toml
[http]
listen = "127.0.0.1:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "worker"
```

Released Rapira 0.8.x also accepts its original top-level pool form:

```toml
listen = "127.0.0.1:8000"

[pool]
entrypoint = "public/index.php"
mode = "worker"
```

Current unreleased Rapira `main` uses the plugin-scoped `[http]` and
`[http.pool]` form shown first.

Start the server with:

```shell
APP_RUNTIME='Rapira\Symfony\Runtime' rapira serve rapira.toml
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
reset explicitly.

Responses use Symfony HttpFoundation's normal `Response::send()` lifecycle.
