<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Feature;

use Rapira\Symfony\Tests\Support\PhpProcess;
use Testo\Assert;
use Testo\Test;

#[Test]
final class FileResponseCompatibilityTest
{
    use PhpProcess;

    public function fileResponseStreamsWhenSymfonyChangesItsInternalProperties(): void
    {
        [$status, $output] = self::runPhpScript(__DIR__ . '/../Fixtures/file-response-compatibility.php');

        Assert::same($status, 0, $output);
        Assert::same(\json_decode($output, true, flags: \JSON_THROW_ON_ERROR), [
            'result' => 0,
            'status' => 200,
            'body' => 'file-body',
            'finalized' => true,
            'sentFiles' => [],
            'logs' => [],
        ]);
    }
}
