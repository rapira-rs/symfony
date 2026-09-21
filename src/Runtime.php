<?php

declare(strict_types=1);

namespace Rapira\Symfony;

use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Symfony\Internal\DispatcherRequestFactory;
use Rapira\Symfony\Internal\ExchangeResponseEmitter;
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

        if ($this->mode === Mode::Worker || $this->mode === Mode::Dispatcher) {
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
            Mode::Worker => new WorkerRunner($application, new NativeRequestLoop()),
            Mode::Dispatcher => new DispatcherRunner(
                $application,
                $this->getHttpDispatcher(),
                new DispatcherRequestFactory(),
                new ExchangeResponseEmitter(),
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

    /**
     * @psalm-suppress MissingPureAnnotation
     * @psalm-suppress UndefinedFunction The function is provided by the Rapira extension and its contract.
     */
    protected function getHttpDispatcher(): HttpDispatcher
    {
        $dispatcher = \Rapira\get_dispatcher();
        if (!$dispatcher instanceof HttpDispatcher) {
            throw new \LogicException(\sprintf(
                'Rapira Dispatcher mode requires an HTTP dispatcher; got %s.',
                $dispatcher::class,
            ));
        }

        return $dispatcher;
    }
}
