<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Closure;
use InvalidArgumentException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function Amp\async;

/**
 * RevoltRunner - runs closures under the Revolt event loop, at most N at a time, each with an optional time limit.
 *
 * Self-contained on purpose (no ALinq type): start() runs `$task(Cancellation)` in its own Fiber through
 * Amp\async() and returns a Future that settles with the task's value (a returned Future is awaited), with
 * its exception, or with a CancelledException as soon as the cancellation passed in, cancel() or the time
 * limit fires (the time limit as a CancelledException whose previous is an Amp\TimeoutException). The task
 * receives the same Cancellation, so an Amp client can abort its I/O; a task that ignores it keeps running
 * in the background and keeps its slot until it returns, so the limit always counts closures really running.
 * When every slot is taken, start() waits for one: it suspends the calling Fiber, or runs the event loop
 * when called outside a Fiber. The loop is the application's (Revolt\EventLoop::getDriver()); no driver is
 * created or replaced here.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt
 * @author     Heliton Junior
 * @copyright  Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda. (https://antevemus.com.br)
 * @license    MIT License
 */
final class RevoltRunner
{
    private int $running = 0;

    /** @var list<Suspension<null>> callers of start() waiting for a slot, in arrival order */
    private array $waiting = [];

    private DeferredCancellation $everything;

    /**
     * @param int $concurrency The most tasks running at the same time (at least 1).
     * @throws InvalidArgumentException When the concurrency is below 1
     */
    public function __construct(private readonly int $concurrency = 8)
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException(sprintf('RevoltRunner expects concurrency to be at least 1, %d given', $concurrency));
        }
        $this->everything = new DeferredCancellation();
    }

    /**
     * Starts `$task(Cancellation $cancellation)` once a slot is free.
     *
     * @param Closure(Cancellation): mixed $task
     * @param float|null $timeout Seconds the task may take, counted from its start; null for no limit.
     * @param Cancellation|null $cancellation Cancels this task (with the others sharing it).
     * @return Future<mixed>
     * @throws InvalidArgumentException When the timeout is not a positive finite number of seconds
     */
    public function start(Closure $task, ?float $timeout = null, ?Cancellation $cancellation = null): Future
    {
        if ($timeout !== null && !($timeout > 0 && is_finite($timeout))) {
            throw new InvalidArgumentException(sprintf('RevoltRunner expects timeout to be a positive number of seconds, %s given', var_export($timeout, true)));
        }
        while ($this->running >= $this->concurrency) {
            $this->waiting[] = $suspension = EventLoop::getSuspension();
            $suspension->suspend();
        }
        $this->running++;

        $sources = [$this->everything->getCancellation()];
        if ($cancellation !== null) {
            $sources[] = $cancellation;
        }
        $timer = $timeout === null ? null : new TimeoutCancellation($timeout);
        if ($timer !== null) {
            $sources[] = $timer;
        }
        $combined = new CompositeCancellation(...$sources);
        $result = new DeferredFuture();
        $settle = static function (?Throwable $error, mixed $value = null) use ($result): void {
            if (!$result->isComplete()) {
                $error === null ? $result->complete($value) : $result->error($error);
            }
        };
        $subscription = $combined->subscribe(static function (CancelledException $reason) use ($settle, $timer, $timeout): void {
            $settle($timer?->isRequested() ? new CancelledException(new TimeoutException(sprintf('The time limit of %s s was exceeded', $timeout))) : $reason);
        });

        async(function () use ($task, $combined, $subscription, $settle): void {
            try {
                $combined->throwIfRequested(); // cancelled before its Fiber began: the task never runs
                $value = $task($combined);
                $settle(null, $value instanceof Future ? $value->await($combined) : $value);
            } catch (Throwable $error) {
                $settle($error);
            } finally {
                $combined->unsubscribe($subscription);
                $this->running--;
                if ($this->waiting !== []) {
                    array_shift($this->waiting)->resume();
                }
            }
        });

        return $result->getFuture();
    }

    /** Cancels every task this runner started and has not finished; later tasks start normally. */
    public function cancel(): void
    {
        $everything = $this->everything;
        $this->everything = new DeferredCancellation();
        $everything->cancel();
    }

    /** The tasks running now (started, not yet returned). */
    public function running(): int
    {
        return $this->running;
    }

    /** The most tasks running at the same time. */
    public function concurrency(): int
    {
        return $this->concurrency;
    }
}
