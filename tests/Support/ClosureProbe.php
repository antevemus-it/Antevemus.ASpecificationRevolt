<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Support;

use Amp\Cancellation;
use Amp\CancelledException;
use Antevemus\ASpecification\Resolution\DataRequest;
use Closure;

use function Amp\delay;

/**
 * Counts what the closures of RevoltDataSource do: how many started, how many run at the same time (the
 * peak), how many completed and how many were cancelled, and the requests and cancellations they received.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */
final class ClosureProbe
{
    public int $started = 0;

    public int $running = 0;

    public int $peak = 0;

    public int $completed = 0;

    public int $cancelled = 0;

    /** @var list<DataRequest> */
    public array $requests = [];

    /** @var list<Cancellation> */
    public array $cancellations = [];

    /**
     * A closure that waits `$seconds` without blocking (Amp\delay(), honouring its cancellation unless told
     * otherwise) and returns `$value` (a closure of the parameters, or a value).
     *
     * @return Closure(array<string, string|int|float|bool>, DataRequest, Cancellation): mixed
     */
    public function waiting(float $seconds, mixed $value = true, bool $honourCancellation = true): Closure
    {
        return function (array $parameters, DataRequest $request, Cancellation $cancellation) use ($seconds, $value, $honourCancellation): mixed {
            $this->started++;
            $this->running++;
            $this->peak = max($this->peak, $this->running);
            $this->requests[] = $request;
            $this->cancellations[] = $cancellation;
            try {
                delay($seconds, cancellation: $honourCancellation ? $cancellation : null);
            } catch (CancelledException $e) {
                $this->cancelled++;
                throw $e;
            } finally {
                $this->running--;
            }
            $this->completed++;

            return $value instanceof Closure ? $value($parameters) : $value;
        };
    }
}
