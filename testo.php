<?php

declare(strict_types=1);

use Rapira\Sdk\Testing\Testo\RunRapiraPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Application\Config\SuiteConfig;

$projectRoot = __DIR__;
// The rapira binary (and its bundled PHP) is fetched into `runtime/bin` on demand when missing.
$rapiraBinary = $projectRoot . '/runtime/bin/rapira' . (\DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(name: 'Feature', location: ['tests/Feature']),
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance'],
            plugins: SuitePlugins::with(
                new RunRapiraPlugin(
                    binary: $rapiraBinary,
                    workingDirectory: $projectRoot . '/tests/App',
                ),
            ),
        ),
    ],
);
