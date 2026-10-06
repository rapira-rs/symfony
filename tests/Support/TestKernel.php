<?php

declare(strict_types=1);

namespace Rapira\Symfony\Tests\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A resident kernel that answers with a scripted closure and records its lifecycle.
 */
final class TestKernel implements HttpKernelInterface, TerminableInterface
{
    /** @var list<Request> Every request handled, in order. */
    public array $requests = [];

    /** @var list<string> `handle:<path>` and `terminate:<path>` in call order; tests may append their own. */
    public array $events = [];

    /** @var \Closure(Request): Response */
    private readonly \Closure $respond;

    /**
     * @param null|\Closure(Request): Response $respond Null answers `200` with the path as the body.
     * @param null|\Closure(Request, Response): void $terminate Runs after the event is recorded.
     */
    public function __construct(
        ?\Closure $respond = null,
        private readonly ?\Closure $terminate = null,
    ) {
        $this->respond = $respond ?? static fn(Request $request): Response => new Response($request->getPathInfo());
    }

    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->requests[] = $request;
        $this->events[] = 'handle:' . $request->getPathInfo();

        return ($this->respond)($request);
    }

    #[\Override]
    public function terminate(Request $request, Response $response): void
    {
        $this->events[] = 'terminate:' . $request->getPathInfo();
        $this->terminate === null or ($this->terminate)($request, $response);
    }
}
