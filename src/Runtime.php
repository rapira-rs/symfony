<?php

declare(strict_types=1);

namespace Rapira\Symfony;

use Rapira\Mode;
use Rapira\Symfony\Internal\NativeRequestLoop;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * @api
 * @psalm-consistent-constructor
 */
class Runtime extends SymfonyRuntime
{
    private readonly Mode $mode;

    /**
     * @param array{
     *     debug?: ?bool,
     *     env?: ?string,
     *     disable_dotenv?: ?bool,
     *     project_dir?: ?string,
     *     prod_envs?: ?string[],
     *     dotenv_path?: ?string,
     *     test_envs?: ?string[],
     *     use_putenv?: ?bool,
     *     runtimes?: ?array,
     *     error_handler?: string|false,
     *     env_var_name?: string,
     *     debug_var_name?: string,
     *     project_dir_var?: string|false,
     *     dotenv_overload?: ?bool,
     *     dotenv_extra_paths?: ?string[],
     *     worker_loop_max?: int,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->mode = $this->getMode();

        if ($this->mode === Mode::Worker) {
            $_SERVER['APP_RUNTIME_MODE'] = 'web=1&worker=1';
        }

        parent::__construct($options);
    }

    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if (!$application instanceof HttpKernelInterface) {
            return parent::getRunner($application);
        }

        return match ($this->mode) {
            Mode::Classic => parent::getRunner($application),
            Mode::Worker => new Runner($application, new NativeRequestLoop()),
            Mode::Dispatcher => throw new \LogicException(
                'Rapira Dispatcher mode is not supported for Symfony HttpKernel applications.',
            ),
        };
    }

    /**
     * @psalm-suppress MissingPureAnnotation
     * @psalm-suppress UndefinedFunction The function is provided by the Rapira extension and its contract.
     * @psalm-suppress MixedReturnStatement
     */
    protected function getMode(): Mode
    {
        return \Rapira\get_mode();
    }
}
