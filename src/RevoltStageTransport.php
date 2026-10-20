<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Antevemus\ASpecification\Resolution\CurlMultiTransport;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use InvalidArgumentException;
use Throwable;

use function Amp\async;

/**
 * RevoltStageTransport - Fetches the Revolt Sources of One Resolution Stage Together, Under the Revolt Event Loop
 *
 * The IStageTransport of antevemus/aspecification 2.6.0 for RevoltDataSource. The DataResolver hands it every
 * pending request of a stage whose source names it; fetchAll() starts, through its RevoltRunner, one Fiber
 * per request that runs the source's closure, at most `maxConcurrency` at a time (the others wait for a free
 * slot, in key order), each with the source's own time limit, and waits for all of them together: the stage
 * costs about as much as its slowest request (times the rounds the limit imposes) instead of their sum, as
 * long as the closures wait on non-blocking I/O.
 *
 * The answer is by key: the value, or the Throwable that failed the request, with the semantics of the
 * core's HTTP and callable sources (the parity of answers):
 * - the closure threw: DataFetchException::sourceFailed() with the original as previous, as a
 *   CallableDataSource; a DataPendingException passes through as it is (the asynchronous provider pattern of
 *   the core: the request is listed by ResolvedData::getPendingRequests());
 * - the source's own time limit ran out: DataFetchException::timeout(), as an HTTP source's own time limit;
 * - the resolution deadline ran out: DataFetchException::resolutionTimeout(). One Amp\TimeoutCancellation of
 *   the time left is shared by every request of the call, so the unfinished requests are cancelled at the
 *   deadline and fetchAll() returns then. With less than CurlMultiTransport::MIN_START_BUDGET left, nothing
 *   is started and every request fails that way, as the core's `curl_multi` batch does.
 * Messages carry the source name only, never a parameter value. A closure receives an Amp\Cancellation
 * (time limit, deadline) to pass to its client; a closure that ignores it keeps running in the background
 * until it returns, holding its slot, but its request has already failed.
 *
 * Another IDeferredDataSource that names this transport is fetched through its own fetch() inside a Fiber,
 * without a time limit of its own, its exception kept as it is (as the core keeps the exception of a source
 * fetched one after the other), and the resolution deadline applies.
 *
 * Loop: the application's Revolt\EventLoop (no driver is created or replaced). Outside a Fiber, the wait runs
 * the loop until the requests settle; inside a Fiber (an Amp server, Amp\async()) it suspends that Fiber and
 * the rest of the application keeps running. One transport caps every fetchAll() through it together,
 * including concurrent resolutions in different Fibers; shared() is the process-wide default.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt
 * @author     Heliton Junior
 * @copyright  Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda. (https://antevemus.com.br)
 * @license    MIT License
 */
final class RevoltStageTransport implements IStageTransport
{
    private static ?self $shared = null;

    private readonly RevoltRunner $runner;

    /**
     * @param int $maxConcurrency The most requests of this transport running at the same time (at least 1).
     * @throws InvalidArgumentException When the limit is below 1
     */
    public function __construct(private readonly int $maxConcurrency = 8)
    {
        if ($maxConcurrency < 1) {
            throw new InvalidArgumentException(sprintf('RevoltStageTransport expects maxConcurrency to be at least 1, %d given', $maxConcurrency));
        }
        $this->runner = new RevoltRunner($maxConcurrency);
    }

    /** The process-wide default transport (maxConcurrency 8), created on first use: the transport of a RevoltDataSource built without one. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /** The most requests of this transport running at the same time. */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, array{IDeferredDataSource, DataRequest}> $pending By request key
     * @param float|null $deadline Monotonic instant (CurlMultiTransport::now()) of the resolution time limit, or null
     * @return array<string, mixed|Throwable> By the same keys
     */
    public function fetchAll(array $pending, ?float $deadline): array
    {
        $results = [];
        $limit = null;
        if ($deadline !== null) {
            $remaining = $deadline - CurlMultiTransport::now();
            if ($remaining < CurlMultiTransport::MIN_START_BUDGET) {
                foreach ($pending as $key => [, $request]) {
                    $results[$key] = DataFetchException::resolutionTimeout($request->source);
                }
                return $results;
            }
            $limit = new TimeoutCancellation($remaining, 'The time limit of the resolution was reached');
        }

        /** @var array<string, Throwable> $thrown what each closure threw, by key */
        $thrown = [];
        $futures = [];
        foreach ($pending as $key => [$source, $request]) {
            $timeout = $source instanceof RevoltDataSource ? $source->getTimeout() : null;
            $task = static function (Cancellation $cancellation) use ($source, $request, $key, &$thrown): mixed {
                try {
                    return $source instanceof RevoltDataSource ? $source->call($request, $cancellation) : $source->fetch($request);
                } catch (Throwable $e) {
                    $thrown[$key] = $e;
                    throw $e;
                }
            };
            // Each request waits for its slot in a Fiber of its own, so waiting for a slot never outlasts the
            // deadline. Every future is awaited below (which marks it handled, even when the deadline ends the wait).
            $futures[$key] = async(fn(): mixed => $this->runner->start($task, $timeout, $limit)->await());
        }

        foreach ($futures as $key => $future) {
            [$source, $request] = $pending[$key];
            try {
                $results[$key] = $future->await($limit);
            } catch (Throwable $e) {
                $results[$key] = self::failure($e, $source, $request, $thrown[$key] ?? null, $limit);
            }
        }

        return $results;
    }

    /** The answer of a failed request: the mapped exception, by what failed it. */
    private static function failure(Throwable $e, IDeferredDataSource $source, DataRequest $request, ?Throwable $thrown, ?Cancellation $limit): Throwable
    {
        if (self::isReasonOf($limit, $e)) {
            return DataFetchException::resolutionTimeout($request->source);
        }
        if (!$source instanceof RevoltDataSource) {
            return $e;
        }
        if ($e !== $thrown) {
            // Neither the deadline nor the closure: the runner's timer, the source's own time limit
            return DataFetchException::timeout($request->source, $source->getTimeout());
        }

        return $e instanceof DataPendingException ? $e : DataFetchException::sourceFailed($request->source, $e);
    }

    /** Whether the exception is the very one the cancellation raised (the deadline, as opposed to a time limit or the closure). */
    private static function isReasonOf(?Cancellation $cancellation, Throwable $e): bool
    {
        try {
            $cancellation?->throwIfRequested();
        } catch (CancelledException $reason) {
            return $reason === $e;
        }

        return false;
    }
}
