<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

/**
 * A stream wrapper over local files that counts the bytes read through it, to tell whether a response
 * read a file it was not supposed to send.
 *
 * @psalm-suppress MissingConstructor, PropertyNotSetInConstructor
 */
final class ReadCountingStream
{
    public const SCHEME = 'counted';

    /** Bytes read through every wrapped file since {@see register()}. */
    public static int $bytesRead = 0;

    /** @var resource|null Set by PHP for every wrapper instance. */
    public $context;

    /** @var resource */
    private $handle;

    public static function register(): void
    {
        self::$bytesRead = 0;
        \in_array(self::SCHEME, \stream_get_wrappers(), true)
            or \stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        \in_array(self::SCHEME, \stream_get_wrappers(), true) and \stream_wrapper_unregister(self::SCHEME);
    }

    /**
     * The wrapped path of a local file.
     */
    public static function path(string $file): string
    {
        return self::SCHEME . '://' . $file;
    }

    public function stream_open(string $path, string $mode): bool
    {
        $handle = \fopen(self::local($path), $mode);
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $data = \fread($this->handle, $count);
        self::$bytesRead += \strlen((string) $data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return \feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return \fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return (int) \ftell($this->handle);
    }

    public function stream_stat(): array|false
    {
        return \fstat($this->handle);
    }

    public function stream_close(): void
    {
        \fclose($this->handle);
    }

    public function url_stat(string $path, int $flags): array|false
    {
        $local = self::local($path);

        return \file_exists($local) ? \stat($local) : false;
    }

    public function unlink(string $path): bool
    {
        return \unlink(self::local($path));
    }

    private static function local(string $path): string
    {
        return \substr($path, \strlen(self::SCHEME . '://'));
    }
}
