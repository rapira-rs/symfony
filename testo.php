<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: new FinderConfig(['src']),
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(include: [
                __DIR__ . '/tests/Unit/ARuntimeTest.php',
                __DIR__ . '/tests/Unit/RunnerTest.php',
            ]),
        ),
    ],
);
