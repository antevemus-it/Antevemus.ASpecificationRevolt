<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Unit;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutException;
use Antevemus\ASpecificationRevolt\RevoltRunner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

/**
 * RevoltRunner: the slot limit, the value of a task (a returned Future awaited), its exception, the time
 * limit, the cancellation passed in, cancel(), and the waiting callers served in arrival order. Copied from
 * antevemus/alinq-revolt 1.0.0 with the class it tests (only the namespaces changed): RevoltRunner is a copy,
 * kept as a copy on purpose, and so are its tests.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Unit
 */
final class RevoltRunnerTest extends TestCase
{
    public function testConcurrencyBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RevoltRunner expects concurrency to be at least 1, 0 given');
        new RevoltRunner(0);
    }

    public function testTheDefaultConcurrencyIsEight(): void
    {
        $this->assertSame(8, (new RevoltRunner())->concurrency());
        $this->assertSame(1, (new RevoltRunner(1))->concurrency());
    }

    /** @return array<string, array{float}> */
    public static function invalidTimeouts(): array
    {
        return ['zero' => [0.0], 'negative' => [-1.0], 'infinite' => [INF], 'not a number' => [NAN]];
    }

    #[DataProvider('invalidTimeouts')]
    public function testANonPositiveOrNonFiniteTimeoutIsRefused(float $timeout): void
    {
        $runner = new RevoltRunner();
        try {
            $runner->start(static fn(): int => 1, $timeout);
            $this->fail('the timeout was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('RevoltRunner expects timeout to be a positive number of seconds, ', $e->getMessage());
        }
        $this->assertSame(0, $runner->running(), 'a refused task takes no slot');
    }

    public function testATaskReturnsItsValueAndAReturnedFutureIsAwaited(): void
    {
        $runner = new RevoltRunner();
        $plain = $runner->start(static fn(): string => 'plain');
        $future = $runner->start(static fn(): Future => async(static function (): string {
            delay(0.001);
            return 'from a future';
        }));
        $suspended = $runner->start(static function (): string {
            delay(0.001);
            return 'after a delay';
        });

        $this->assertSame(['plain', 'from a future', 'after a delay'], [$plain->await(), $future->await(), $suspended->await()]);
        $this->assertSame(0, $runner->running());
    }

    public function testATaskExceptionSettlesTheFuture(): void
    {
        $original = new RuntimeException('boom');
        $future = (new RevoltRunner())->start(static fn() => throw $original);
        try {
            $future->await();
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }
    }

    public function testNoMoreThanTheLimitRunAtOnceAndWaitingCallersAreServedInOrder(): void
    {
        $runner = new RevoltRunner(2);
        $running = 0;
        $peak = 0;
        $order = [];
        $futures = [];
        foreach (range(1, 6) as $i) {
            $futures[] = $runner->start(static function () use ($i, &$running, &$peak, &$order): int {
                $running++;
                $peak = max($peak, $running);
                $order[] = $i;
                delay(0.005);
                $running--;
                return $i;
            });
            $this->assertLessThanOrEqual(2, $runner->running(), 'start() waits for a slot');
        }

        $this->assertSame([1, 2, 3, 4, 5, 6], Future\await($futures));
        $this->assertSame(2, $peak);
        $this->assertSame([1, 2, 3, 4, 5, 6], $order, 'the waiting callers start in arrival order');
        $this->assertSame(0, $runner->running());
    }

    public function testAWaitingCallerInsideAFiberIsSuspendedNotBlocked(): void
    {
        $runner = new RevoltRunner(1);
        $log = [];
        $first = async(static function () use ($runner, &$log): int {
            $log[] = 'first starts';
            return $runner->start(static function () use (&$log): int {
                delay(0.01);
                $log[] = 'task 1 ends';
                return 1;
            })->await();
        });
        $second = async(static function () use ($runner, &$log): int {
            $future = $runner->start(static function () use (&$log): int {
                $log[] = 'task 2 runs';
                return 2;
            });
            $log[] = 'second got a slot';
            return $future->await();
        });

        $this->assertSame([1, 2], [$first->await(), $second->await()]);
        $this->assertSame(['first starts', 'task 1 ends', 'second got a slot', 'task 2 runs'], $log);
    }

    public function testTheTimeLimitSettlesWithACancelledExceptionCarryingATimeoutException(): void
    {
        $runner = new RevoltRunner();
        $seen = null;
        $future = $runner->start(static function (Cancellation $cancellation) use (&$seen): string {
            try {
                delay(1.0, cancellation: $cancellation);
            } catch (CancelledException $e) {
                $seen = $e;
                throw $e;
            }
            return 'late';
        }, 0.02);

        $started = microtime(true);
        try {
            $future->await();
            $this->fail('no timeout');
        } catch (CancelledException $e) {
            $this->assertInstanceOf(TimeoutException::class, $e->getPrevious());
            $this->assertSame('The time limit of 0.02 s was exceeded', $e->getPrevious()->getMessage());
        }
        $this->assertLessThan(0.5, microtime(true) - $started);
        delay(0.001);
        $this->assertInstanceOf(CancelledException::class, $seen, 'the task received the cancellation of its time limit');
        $this->assertSame(0, $runner->running());
    }

    public function testATaskThatIgnoresItsCancellationTimesOutButKeepsItsSlotUntilItReturns(): void
    {
        $runner = new RevoltRunner(1);
        $finished = false;
        $future = $runner->start(static function () use (&$finished): string {
            delay(0.06);
            $finished = true;
            return 'late';
        }, 0.01);

        try {
            $future->await();
            $this->fail('no timeout');
        } catch (CancelledException $e) {
            $this->assertInstanceOf(TimeoutException::class, $e->getPrevious());
        }
        $this->assertFalse($finished);
        $this->assertSame(1, $runner->running(), 'the task still runs');
        $this->assertSame('next', $runner->start(static fn(): string => 'next')->await(), 'the next task waits for the slot');
        $this->assertTrue($finished);
    }

    public function testATaskWithinItsTimeLimitIsNotCancelled(): void
    {
        $future = (new RevoltRunner())->start(static function (Cancellation $cancellation): string {
            delay(0.005, cancellation: $cancellation);
            return 'in time';
        }, 1.0);
        $this->assertSame('in time', $future->await());
    }

    public function testTheCancellationPassedInCancelsOnlyItsTasks(): void
    {
        $runner = new RevoltRunner();
        $scope = new DeferredCancellation();
        $task = static function (Cancellation $cancellation): string {
            delay(0.05, cancellation: $cancellation);
            return 'done';
        };
        $cancelled = $runner->start($task, null, $scope->getCancellation());
        $other = $runner->start($task);
        $scope->cancel();

        try {
            $cancelled->await();
            $this->fail('not cancelled');
        } catch (CancelledException $e) {
            $this->assertNull($e->getPrevious(), 'not a time limit');
        }
        $this->assertSame('done', $other->await());
    }

    public function testCancelCancelsEverythingInFlightAndLaterTasksRun(): void
    {
        $runner = new RevoltRunner();
        $task = static function (Cancellation $cancellation): string {
            delay(0.05, cancellation: $cancellation);
            return 'done';
        };
        $futures = [$runner->start($task), $runner->start($task)];
        $runner->cancel();
        foreach ($futures as $future) {
            try {
                $future->await();
                $this->fail('not cancelled');
            } catch (CancelledException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('done', $runner->start($task)->await());
    }

    public function testAFutureReturnedByTheTaskIsAbandonedOnTimeout(): void
    {
        $future = (new RevoltRunner())->start(static fn(): Future => async(static function (): string {
            delay(0.05);
            return 'late';
        }), 0.01);
        $this->expectException(CancelledException::class);
        $future->await();
    }
}
