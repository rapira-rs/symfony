<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Symfony\Runtime;

final class RuntimeForMode extends Runtime
{
    public function __construct(
        private readonly Mode $runtimeMode,
        array $options = [],
        private readonly ?HttpDispatcher $httpDispatcher = null,
    ) {
        parent::__construct($options);
    }

    protected function getMode(): Mode
    {
        return $this->runtimeMode;
    }

    protected function getHttpDispatcher(): HttpDispatcher
    {
        return $this->httpDispatcher ?? parent::getHttpDispatcher();
    }
}
