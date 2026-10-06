<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

/**
 * The boot-time `$_SERVER` entries a resident process hands on to every request: the environment and,
 * in Worker mode, the script, never a request's metadata.
 *
 * The host builds a request's `$_SERVER` from the request alone, so the variables Symfony's Dotenv put
 * there at boot, `APP_ENV` and `APP_SECRET` among them, would be gone from the second request on.
 *
 * @internal
 * @psalm-pure
 */
final class BootServer
{
    /** Where the entry script lies, which CGI-era base URL detection reads. */
    private const SCRIPT_KEYS = [
        'DOCUMENT_ROOT',
        'ORIG_SCRIPT_NAME',
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
     * @param bool $script Keep the script location as well. Dispatcher mode leaves it out: Rapira serves
     *        from the root with no document root, so Symfony then finds an empty base URL and the whole
     *        path is the path info.
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    public static function variables(array $server, bool $script): array
    {
        $variables = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($server as $key => $value) {
            if (\is_string($key) && self::isBootVariable($key, $script)) {
                $variables[$key] = $value;
            }
        }

        return $variables;
    }

    /**
     * @psalm-pure
     */
    private static function isBootVariable(string $key, bool $script): bool
    {
        if (\in_array($key, self::SCRIPT_KEYS, true)) {
            return $script;
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
