<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Acceptance\Support;

use Rapira\Sdk\Common\Mode;
use Testo\Assert;

/**
 * The requests every mode must answer identically. A test case picks the mode with `RunRapira` and
 * names it in {@see mode()}. Each case listens on its own `ADDRESS`, so a server that outlived the
 * previous case can never answer for the current one.
 */
trait ServerRequests
{
    public function homeReturnsOk(): void
    {
        [$status, $body] = $this->request('/');

        Assert::same($status, 200);
        Assert::same($body, 'OK');
    }

    public function processRunsInTheRequestedMode(): void
    {
        [, $body] = $this->request('/mode');

        Assert::same($body, $this->mode()->value);
    }

    abstract protected function mode(): Mode;

    /**
     * A response that flushes from inside its callback arrives whole, and the application goes on serving.
     *
     * @param bool $sameWorker Also require the next request to reach the same boot of the application,
     *        which only a resident mode keeps.
     */
    private function assertFlushingStreamArrivesWhole(bool $sameWorker): void
    {
        [, $boot] = $this->request('/boot');

        [$status, $body] = $this->request('/stream');

        Assert::same($status, 200);
        Assert::same($body, 'onetwo');
        Assert::same($this->request('/'), [200, 'OK']);
        $sameWorker and Assert::same($this->request('/boot')[1], $boot);
    }

    /**
     * @param array<int, mixed> $options Additional cURL options, for example a multipart upload.
     * @return array{0: int, 1: string} The response status code and body.
     */
    private function request(string $path, array $options = []): array
    {
        $curl = \curl_init();
        \curl_setopt_array($curl, $options + [
            \CURLOPT_URL => 'http://' . self::ADDRESS . $path,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 10,
        ]);

        $body = \curl_exec($curl);
        if (!\is_string($body)) {
            throw new \RuntimeException(\sprintf('Request to %s failed: %s', $path, \curl_error($curl)));
        }

        return [(int) \curl_getinfo($curl, \CURLINFO_HTTP_CODE), $body];
    }
}
