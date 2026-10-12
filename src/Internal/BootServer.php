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

    // Exact CGI names registered by Rapira's SAPI and PHP itself. Prefixes such as SERVER_ or
    // CONTENT_ also belong to legitimate application settings and must not be discarded.
    private const REQUEST_KEYS = [
        'AUTH_TYPE',
        'CONTENT_LENGTH',
        'CONTENT_TYPE',
        'DOCUMENT_URI',
        'GATEWAY_INTERFACE',
        'HTTPS',
        'ORIG_PATH_INFO',
        'PATH_INFO',
        'PHP_AUTH_DIGEST',
        'PHP_AUTH_PW',
        'PHP_AUTH_USER',
        'QUERY_STRING',
        'REDIRECT_STATUS',
        'REMOTE_ADDR',
        'REMOTE_HOST',
        'REMOTE_IDENT',
        'REMOTE_PORT',
        'REMOTE_USER',
        'REQUEST_METHOD',
        'REQUEST_SCHEME',
        'REQUEST_TIME',
        'REQUEST_TIME_FLOAT',
        'REQUEST_URI',
        'SERVER_ADDR',
        'SERVER_NAME',
        'SERVER_PORT',
        'SERVER_PROTOCOL',
        'SERVER_SOFTWARE',
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

        return !\str_starts_with($key, 'HTTP_');
    }
}
