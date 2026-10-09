<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

/**
 * Runs process-global scenarios in a bounded PHP subprocess.
 */
trait PhpProcess
{
    /**
     * @return array{int, string} The exit code and the combined output.
     */
    private static function runPhpScript(string $script): array
    {
        $process = \proc_open(
            [\PHP_BINARY, '-dxdebug.mode=off', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the PHP subprocess.');
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $output = '';
        $exitCode = null;
        $deadline = \microtime(true) + 10;
        try {
            do {
                $output .= \stream_get_contents($pipes[1]);
                $output .= \stream_get_contents($pipes[2]);
                $status = \proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (\microtime(true) >= $deadline) {
                    throw new \RuntimeException('PHP subprocess timed out.');
                }
                \usleep(10_000);
            } while (true);

            $output .= \stream_get_contents($pipes[1]);
            $output .= \stream_get_contents($pipes[2]);
        } finally {
            if (\proc_get_status($process)['running']) {
                \proc_terminate($process);
            }
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $closeStatus = \proc_close($process);
        }

        return [$exitCode === -1 ? $closeStatus : $exitCode, $output];
    }
}
