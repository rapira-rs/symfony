<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * The boot-time `$_SERVER` entries a resident process hands on to every request: the environment and
 * the script, never a request's metadata.
 *
 * The host builds a request's `$_SERVER` from the request alone, so the variables Symfony's Dotenv put
 * there at boot, `APP_ENV` and `APP_SECRET` among them, would be gone from the second request on.
 *
 * @internal
 * @psalm-pure
 */
final class BootServer
{
    private const SCRIPT_KEYS = [
        'DOCUMENT_ROOT',
        'PATH_TRANSLATED',
        'PHP_SELF',
        'SCRIPT_FILENAME',
        'SCRIPT_NAME',
    ];
    private const REQUEST_PREFIXES = [
        'AUTH_',
        'CONTENT_',
        'HTTP_',
        'PHP_AUTH_',
        'REMOTE_',
        'REQUEST_',
        'SERVER_',
    ];
    private const REQUEST_KEYS = [
        'DOCUMENT_URI',
        'GATEWAY_INTERFACE',
        'HTTPS',
        'ORIG_PATH_INFO',
        'PATH_INFO',
        'QUERY_STRING',
        'REDIRECT_STATUS',
    ];

    /**
     * @param array<array-key, mixed> $server The `$_SERVER` of the boot.
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    public static function variables(array $server): array
    {
        $variables = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($server as $key => $value) {
            if (\is_string($key) && self::isBootVariable($key)) {
                $variables[$key] = $value;
            }
        }

        return $variables;
    }

    /**
     * @psalm-pure
     */
    private static function isBootVariable(string $key): bool
    {
        if (\in_array($key, self::SCRIPT_KEYS, true)) {
            return true;
        }
        if (\in_array($key, self::REQUEST_KEYS, true)) {
            return false;
        }

        foreach (self::REQUEST_PREFIXES as $prefix) {
            if (\str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
