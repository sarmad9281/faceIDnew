<?php
/**
 * SARMAD Attendance - middleware pipeline.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;


final class Pipeline
{
    /** @var array<int,callable> */
    private array $pipes = [];

    private $destination;

    public static function make(): self
    {
        return new self();
    }

    public function pipe(callable $pipe): self
    {
        $this->pipes[] = $pipe;

        return $this;
    }

    public function then(callable $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function handle(Request $request): Response
    {
        $chain = array_reduce(
            array_reverse($this->pipes),
            function (callable $next, callable $pipe): callable {
                return static function (Request $request) use ($pipe, $next): Response {
                    return $pipe($request, $next);
                };
            },
            $this->destination
        );

        $response = $chain($request);

        if (!$response instanceof Response) {
            $response = Response::html('');
        }

        $response->send();

        return $response;
    }
}
