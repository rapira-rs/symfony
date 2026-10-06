<?php

declare(strict_types=1);

namespace Rapira\Symfony;

use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Symfony\Internal\ClassicRunner;
use Rapira\Symfony\Internal\DispatcherRunner;
use Rapira\Symfony\Internal\WorkerRunner;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Symfony Runtime that serves an HTTP kernel in the mode the Rapira host launched the process in.
 *
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
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->mode = \Rapira\get_mode();

        if ($this->mode !== Mode::Classic) {
            $_SERVER['APP_RUNTIME_MODE'] = 'web=1&worker=1';
        }

        parent::__construct($options);
    }

    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if (!$application instanceof HttpKernelInterface) {
            // A resident process keeps serving only through a kernel; anything else would run once and exit.
            $this->mode === Mode::Classic or throw new \LogicException(\sprintf(
                'Rapira %s mode serves only an %s application; got %s.',
                $this->mode->name,
                HttpKernelInterface::class,
                \get_debug_type($application),
            ));

            return parent::getRunner($application);
        }

        return match ($this->mode) {
            Mode::Classic => new ClassicRunner($application, (bool) ($this->options['debug'] ?? false)),
            Mode::Worker => new WorkerRunner($application),
            Mode::Dispatcher => new DispatcherRunner($application, self::httpDispatcher()),
        };
    }

    private static function httpDispatcher(): HttpDispatcher
    {
        $dispatcher = \Rapira\get_dispatcher();
        $dispatcher instanceof HttpDispatcher or throw new \LogicException(\sprintf(
            'Rapira Dispatcher mode requires an HTTP dispatcher; got %s.',
            $dispatcher::class,
        ));

        return $dispatcher;
    }
}
