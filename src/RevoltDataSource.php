<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt;

use Amp\Cancellation;
use Amp\Future;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use Throwable;

/**
 * RevoltDataSource - A Non-HTTP Data Source Fetched in a Fiber, Under the Revolt Event Loop
 *
 * The IDeferredDataSource of antevemus/aspecification 2.6.0 for data that does not come over the core's
 * `curl_multi` batch: an Amp or Revolt client, an asynchronous SDK, a database driver that suspends. Inside a
 * resolution stage the DataResolver never calls fetch(): it hands every pending request of the stage whose
 * source shares the same RevoltStageTransport to one fetchAll(), which runs the closures together, each in
 * its own Fiber.
 *
 * <code>
 * $registry->register('kyc.status', new RevoltDataSource(
 *     fn(array $p, DataRequest $request, Cancellation $cancellation): string => $kyc->status($p['document'], $cancellation),
 *     cost: 1,
 *     timeout: 2.0,
 * ));
 * </code>
 *
 * The closure receives the parameters of the request (sorted by name) and the DataRequest, as the callable of
 * a CallableDataSource does (`fn(array $p)` works), and, when it declares a third parameter (or is variadic),
 * the Amp\Cancellation of the request (its time limit and the resolution deadline), to pass to an Amp client.
 * It runs in a Fiber under the loop: it may suspend, and it may return a value or an Amp\Future (awaited).
 *
 * Failures, by the same factories as the core's sources: an exception of the closure becomes
 * DataFetchException::sourceFailed() (the original as previous), except a DataPendingException, which passes
 * through as it is; the time limit of the source (`timeout`, seconds from the start of the request)
 * DataFetchException::timeout(); the resolution deadline DataFetchException::resolutionTimeout().
 *
 * fetch() is the synchronous path outside a resolution scope (`$spec->evaluate($candidate)` without the
 * resolver): one request through the same transport, its value returned or its failure thrown.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt
 * @author     Heliton Junior
 * @copyright  Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda. (https://antevemus.com.br)
 * @license    MIT License
 */
final class RevoltDataSource implements IDeferredDataSource
{
    /** Whether the closure receives the Cancellation as a third argument. */
    private readonly bool $withCancellation;

    /**
     * @param Closure(array<string, string|int|float|bool>, DataRequest, Cancellation): mixed $fetch Returns the
     *        value (or an Amp\Future of it); runs in a Fiber under the Revolt loop
     * @param int $cost Relative cost (lower is cheaper; the tiered policy fetches cheaper sources first)
     * @param int|null $ttl Seconds a value may be cached between resolutions (null or 0: never)
     * @param float $timeout Time limit of one request, in seconds, from its start
     * @param RevoltStageTransport|null $transport The transport of the source (null: RevoltStageTransport::shared())
     * @throws InvalidArgumentException When the TTL is negative or the time limit is not a positive finite number
     */
    public function __construct(
        private readonly Closure $fetch,
        private readonly int $cost = 0,
        private readonly ?int $ttl = null,
        private readonly float $timeout = 5.0,
        private readonly ?RevoltStageTransport $transport = null
    ) {
        if ($ttl !== null && $ttl < 0) {
            throw new InvalidArgumentException('The TTL of a data source cannot be negative.');
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('The time limit of a Revolt data source must be a positive number of seconds.');
        }
        $reflection = new ReflectionFunction($fetch);
        $this->withCancellation = !$reflection->isInternal() && ($reflection->isVariadic() || $reflection->getNumberOfParameters() >= 3);
    }

    /**
     * {@inheritdoc}
     *
     * One request through the transport, outside a resolution scope (no resolution deadline; the time limit
     * of the source applies). Inside a Fiber it suspends that Fiber; outside, it runs the event loop.
     *
     * @throws DataFetchException|DataPendingException The failure of the request
     */
    public function fetch(DataRequest $request): mixed
    {
        $key = $request->getKey();
        $result = $this->getTransport()->fetchAll([$key => [$this, $request]], null)[$key];
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** The transport given to the constructor, or the shared one. */
    public function getTransport(): RevoltStageTransport
    {
        return $this->transport ?? RevoltStageTransport::shared();
    }

    /** {@inheritdoc} */
    public function getCost(): int
    {
        return $this->cost;
    }

    /** {@inheritdoc} */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    /** Time limit of one request, in seconds. */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * @internal Runs the closure for one request (called by RevoltStageTransport inside a Fiber); a returned
     * Future is awaited with the request's cancellation. Exceptions are raised as they are.
     */
    public function call(DataRequest $request, Cancellation $cancellation): mixed
    {
        $value = $this->withCancellation
            ? ($this->fetch)($request->parameters, $request, $cancellation)
            : ($this->fetch)($request->parameters, $request);

        return $value instanceof Future ? $value->await($cancellation) : $value;
    }
}
