<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Unit;

use Amp\Cancellation;
use Amp\Future;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\CurlMultiTransport;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\ClosureProbe;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * RevoltStageTransport: answers by key, the failure mapping (closure, the source's time limit, the resolution
 * deadline, DataPendingException), maxConcurrency, the waiting requests bounded by the deadline, a foreign
 * deferred source, the shared instance and the event loop (outside and inside a Fiber).
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Unit
 */
final class RevoltStageTransportTest extends TestCase
{
    public function testMaxConcurrencyBelowOneIsRefused(): void
    {
        foreach ([0, -1] as $limit) {
            try {
                new RevoltStageTransport($limit);
                $this->fail("maxConcurrency $limit was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("RevoltStageTransport expects maxConcurrency to be at least 1, $limit given", $e->getMessage());
            }
        }
        $this->assertSame(1, (new RevoltStageTransport(1))->getMaxConcurrency());
    }

    public function testTheDefaultLimitIsEightAndSharedIsOneInstance(): void
    {
        $this->assertSame(8, (new RevoltStageTransport())->getMaxConcurrency());
        $shared = RevoltStageTransport::shared();
        $this->assertSame($shared, RevoltStageTransport::shared(), 'one process-wide instance');
        $this->assertSame(8, $shared->getMaxConcurrency());
        $this->assertNotSame($shared, new RevoltStageTransport());
        $this->assertSame($shared, (new RevoltDataSource(static fn(array $p): bool => true))->getTransport(), 'the transport of a source built without one');
    }

    public function testNothingPendingAnswersNothing(): void
    {
        $this->assertSame([], (new RevoltStageTransport())->fetchAll([], null));
        $this->assertSame([], (new RevoltStageTransport())->fetchAll([], CurlMultiTransport::now() + 1.0));
    }

    public function testEachRequestIsAnsweredByItsKey(): void
    {
        $transport = new RevoltStageTransport();
        $probe = new ClosureProbe();
        $double = new RevoltDataSource($probe->waiting(0.02, static fn(array $p): int => $p['n'] * 2), transport: $transport);
        $future = new RevoltDataSource(static fn(array $p): Future => async(static function () use ($p): string {
            delay(0.01);
            return "future {$p['n']}";
        }), transport: $transport);
        $pending = self::pending([
            ['double', $double, ['n' => 3]],
            ['future', $future, ['n' => 4]],
            ['double', $double, ['n' => 5]],
        ]);

        $results = $transport->fetchAll($pending, null);

        $this->assertSame(array_keys($pending), array_keys($results), 'answered by the keys received, in their order');
        $this->assertSame([6, 'future 4', 10], array_values($results));
        $this->assertSame(2, $probe->started);
        $this->assertSame([array_keys($pending)[0], array_keys($pending)[2]], array_map(static fn(DataRequest $r): string => $r->getKey(), $probe->requests), 'the closure receives the DataRequest');
        $this->assertContainsOnlyInstancesOf(Cancellation::class, $probe->cancellations);
        $this->assertCount(2, $probe->cancellations);
    }

    public function testAnExceptionOfTheClosureFailsItsRequestOnly(): void
    {
        $transport = new RevoltStageTransport();
        $boom = new RuntimeException('bureau offline for 111.444.777-35');
        $failing = new RevoltDataSource(static function (array $p) use ($boom): never {
            delay(0.001);
            throw $boom;
        }, transport: $transport);
        $healthy = new RevoltDataSource(static fn(array $p): string => 'ok', transport: $transport);
        $pending = self::pending([['bureau', $failing, ['document' => '111.444.777-35']], ['other', $healthy, ['document' => '1']]]);

        $results = $transport->fetchAll($pending, null);
        [$failed, $ok] = array_values($results);

        $this->assertSame('ok', $ok);
        $this->assertInstanceOf(DataFetchException::class, $failed);
        $this->assertSame('Data source "bureau" failed (RuntimeException).', $failed->getMessage(), 'the source and the class, never a parameter value nor the original message');
        $this->assertSame('bureau', $failed->getSourceName());
        $this->assertSame($boom, $failed->getPrevious());
        $this->assertNull($failed->getStatusCode());
    }

    public function testTheTimeLimitOfTheSourceIsTheCoresSourceTimeout(): void
    {
        $transport = new RevoltStageTransport();
        $probe = new ClosureProbe();
        $slow = new RevoltDataSource($probe->waiting(1.0), timeout: 0.05, transport: $transport);
        $patient = new RevoltDataSource($probe->waiting(0.08, 'patient'), timeout: 1.0, transport: $transport);
        $pending = self::pending([['slow', $slow, []], ['patient', $patient, []]]);

        $started = hrtime(true);
        $results = $transport->fetchAll($pending, CurlMultiTransport::now() + 2.0);
        $elapsed = (hrtime(true) - $started) / 1e6;
        delay(0.001);

        [$failed, $ok] = array_values($results);
        $this->assertSame('patient', $ok, 'a request within its own limit is not cut by another one\'s');
        $this->assertInstanceOf(DataFetchException::class, $failed);
        $this->assertSame('Data source "slow" did not answer within its time limit of 0.050 s.', $failed->getMessage());
        $this->assertSame('slow', $failed->getSourceName());
        $this->assertSame(1, $probe->cancelled, 'the closure past its limit is cancelled through its Cancellation');
        $this->assertLessThan(500, $elapsed, sprintf('fetchAll() took %.0f ms', $elapsed));
    }

    public function testAClosureThatIgnoresItsCancellationStillFailsAtItsTimeLimit(): void
    {
        $transport = new RevoltStageTransport();
        $probe = new ClosureProbe();
        $source = new RevoltDataSource($probe->waiting(0.2, honourCancellation: false), timeout: 0.03, transport: $transport);

        $started = hrtime(true);
        $result = $transport->fetchAll(self::pending([['deaf', $source, []]]), null);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->assertInstanceOf(DataFetchException::class, $result[array_key_first($result)]);
        $this->assertStringContainsString('did not answer within its time limit of 0.030 s', $result[array_key_first($result)]->getMessage());
        $this->assertLessThan(150, $elapsed, sprintf('the request failed at its limit, not when the closure returned: %.0f ms', $elapsed));
        delay(0.25); // the closure keeps running in the background until it returns
        $this->assertSame(1, $probe->completed);
    }

    public function testTheResolutionDeadlineCancelsEveryUnfinishedRequest(): void
    {
        $transport = new RevoltStageTransport();
        $probe = new ClosureProbe();
        $sources = [];
        for ($i = 0; $i < 16; $i++) {
            $sources[] = ["s$i", new RevoltDataSource($probe->waiting(1.0), transport: $transport), ['i' => $i]];
        }

        $started = hrtime(true);
        $results = $transport->fetchAll(self::pending($sources), CurlMultiTransport::now() + 0.05);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->assertCount(16, $results);
        foreach (array_values($results) as $i => $failure) {
            $this->assertInstanceOf(DataFetchException::class, $failure);
            $this->assertSame("The time limit of the resolution was reached before data source \"s$i\" answered.", $failure->getMessage());
        }
        $this->assertGreaterThan(40, $elapsed, sprintf('fetchAll() returns at the deadline: %.0f ms', $elapsed));
        $this->assertLessThan(300, $elapsed, sprintf('fetchAll() returns at the deadline: %.0f ms', $elapsed));

        delay(0.02);
        $this->assertSame(8, $probe->started, 'the first eight started; the other eight never ran their closure');
        $this->assertSame(8, $probe->cancelled, 'every closure that started was cancelled');
        $this->assertSame(0, $probe->completed);
        $this->assertSame(0, $probe->running, 'nothing keeps running');
        $this->assertSame([], self::activeWatchers(), 'no timer of the requests is left in the event loop');
    }

    public function testTheDeadlineThroughTheResolverAt50Milliseconds(): void
    {
        $probe = new ClosureProbe();
        $transport = new RevoltStageTransport(16);
        $registry = new DataSourceRegistry();
        $tree = Spec::alwaysTrue();
        for ($i = 0; $i < 16; $i++) {
            $registry->register("r$i", new RevoltDataSource($probe->waiting(1.0), transport: $transport));
            $tree = $tree->and(Spec::external("r$i", ['document' => 'cpf'], Spec::isTrue()));
        }
        $customer = (object) ['cpf' => '111'];

        $started = hrtime(true);
        $data = (new DataResolver($registry))->resolve($tree, $customer, ResolutionPolicy::concurrent(timeout: 0.05));
        $elapsed = (hrtime(true) - $started) / 1e6;
        $result = $data->evaluate($tree, $customer);

        $this->assertLessThan(300, $elapsed, sprintf('resolve() took %.0f ms', $elapsed));
        $this->assertCount(16, $data->getFailedRequests());
        $this->assertTrue($result->isError);
        $this->assertSame('The time limit of the resolution was reached before data source "r0" answered.', $result->exception?->getMessage());
        delay(0.02);
        $this->assertSame(16, $probe->started);
        $this->assertSame(16, $probe->cancelled);
        $this->assertSame(0, $probe->running);
        $this->assertSame([], self::activeWatchers());
    }

    public function testAnExhaustedDeadlineStartsNothing(): void
    {
        $transport = new RevoltStageTransport();
        $probe = new ClosureProbe();
        $source = new RevoltDataSource($probe->waiting(0.001), transport: $transport);
        foreach ([CurlMultiTransport::now() + CurlMultiTransport::MIN_START_BUDGET / 2, CurlMultiTransport::now() - 1.0] as $deadline) {
            $results = $transport->fetchAll(self::pending([['a', $source, []], ['b', $source, []]]), $deadline);
            $this->assertSame(['The time limit of the resolution was reached before data source "a" answered.', 'The time limit of the resolution was reached before data source "b" answered.'], array_map(static fn(Throwable $e): string => $e->getMessage(), array_values($results)));
        }
        $this->assertSame(0, $probe->started, 'less than the minimum budget of the core: no closure is called');

        $results = $transport->fetchAll(self::pending([['a', $source, []]]), CurlMultiTransport::now() + CurlMultiTransport::MIN_START_BUDGET * 20);
        $this->assertSame([true], array_values($results), 'a budget above the minimum starts the request');
    }

    public function testTheDeadlineAndTheSourceLimitAreToldApart(): void
    {
        $transport = new RevoltStageTransport();
        $source = new RevoltDataSource((new ClosureProbe())->waiting(1.0), timeout: 0.03, transport: $transport);
        $byLimit = $transport->fetchAll(self::pending([['x', $source, []]]), CurlMultiTransport::now() + 0.5);
        $this->assertSame('Data source "x" did not answer within its time limit of 0.030 s.', array_values($byLimit)[0]->getMessage(), 'the source\'s limit comes first');

        $source = new RevoltDataSource((new ClosureProbe())->waiting(1.0), timeout: 0.5, transport: $transport);
        $byDeadline = $transport->fetchAll(self::pending([['x', $source, []]]), CurlMultiTransport::now() + 0.03);
        $this->assertSame('The time limit of the resolution was reached before data source "x" answered.', array_values($byDeadline)[0]->getMessage(), 'the deadline comes first');
        delay(0.001);
    }

    public function testAValueReadyBeforeTheDeadlineIsKeptWhateverTheOrderOfTheKeys(): void
    {
        $transport = new RevoltStageTransport();
        $slow = new RevoltDataSource((new ClosureProbe())->waiting(1.0), transport: $transport);
        $fast = new RevoltDataSource((new ClosureProbe())->waiting(0.005, 'fast'), transport: $transport);
        $results = $transport->fetchAll(self::pending([['slow', $slow, []], ['fast', $fast, []]]), CurlMultiTransport::now() + 0.05);
        [$late, $ready] = array_values($results);

        $this->assertInstanceOf(DataFetchException::class, $late);
        $this->assertSame('fast', $ready, 'awaited after the deadline, but finished before it');
        delay(0.001);
    }

    public function testMaxConcurrencyIsHonoured(): void
    {
        foreach ([1 => [1, 600, 2000], 3 => [3, 250, 900], 8 => [8, 180, 600], 16 => [16, 90, 400]] as $limit => [$peak, $min, $max]) {
            $transport = new RevoltStageTransport($limit);
            $probe = new ClosureProbe();
            $sources = [];
            for ($i = 0; $i < 16; $i++) {
                $sources[] = ["s$i", new RevoltDataSource($probe->waiting($limit === 1 ? 0.04 : 0.1, $i), transport: $transport), []];
            }
            $started = hrtime(true);
            $results = $transport->fetchAll(self::pending($sources), null);
            $elapsed = (hrtime(true) - $started) / 1e6;

            $this->assertSame(range(0, 15), array_values($results), "limit $limit: every value, by key");
            $this->assertSame($peak, $probe->peak, "limit $limit: closures running at the same time");
            $this->assertGreaterThan($min, $elapsed, sprintf('limit %d: %.0f ms', $limit, $elapsed));
            $this->assertLessThan($max, $elapsed, sprintf('limit %d: %.0f ms', $limit, $elapsed));
        }
    }

    public function testRequestsWaitingForASlotDoNotOutlastTheDeadline(): void
    {
        $transport = new RevoltStageTransport(1);
        $probe = new ClosureProbe();
        $source = new RevoltDataSource($probe->waiting(0.3, honourCancellation: false), transport: $transport);

        $started = hrtime(true);
        $results = $transport->fetchAll(self::pending([['a', $source, ['n' => 1]], ['b', $source, ['n' => 2]], ['c', $source, ['n' => 3]]]), CurlMultiTransport::now() + 0.05);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->assertLessThan(200, $elapsed, sprintf('fetchAll() returns at the deadline even though the slot is held: %.0f ms', $elapsed));
        foreach ($results as $failure) {
            $this->assertInstanceOf(DataFetchException::class, $failure);
            $this->assertStringStartsWith('The time limit of the resolution was reached', $failure->getMessage());
        }
        delay(0.35);
        $this->assertSame(1, $probe->started, 'the requests that waited for the slot never ran their closure');
        $this->assertSame(1, $probe->completed, 'the one that ignored its cancellation returned in the background');
    }

    public function testADataPendingExceptionPassesThroughAsItIs(): void
    {
        $transport = new RevoltStageTransport();
        $pendingException = new DataPendingException('bureau', 'T-1', 30);
        $wrapped = new RuntimeException('wrapped', 0, new DataPendingException('bureau'));
        $source = new RevoltDataSource(static fn(array $p): never => throw ($p['n'] === 1 ? $pendingException : $wrapped), transport: $transport);
        [$asItIs, $inAChain] = array_values($transport->fetchAll(self::pending([['bureau', $source, ['n' => 1]], ['bureau', $source, ['n' => 2]]]), null));

        $this->assertSame($pendingException, $asItIs, 'not wrapped');
        $this->assertInstanceOf(DataFetchException::class, $inAChain, 'another exception is wrapped, even with a pending one inside');
        $this->assertTrue(DataPendingException::isPendingThrowable($inAChain));

        $registry = (new DataSourceRegistry())->register('bureau', $source);
        $leaf = Spec::external('bureau', ['n' => 'n'], Spec::isTrue());
        $data = (new DataResolver($registry))->resolve($leaf, (object) ['n' => 1]);
        $this->assertSame(['bureau'], array_map(static fn(DataRequest $r): string => $r->source, $data->getPendingRequests()));
        $result = $data->evaluate($leaf, (object) ['n' => 1]);
        $this->assertTrue($result->isPending());
        $this->assertSame($pendingException, $result->exception);
    }

    public function testAnotherDeferredSourceIsFetchedThroughItsOwnFetch(): void
    {
        $transport = new RevoltStageTransport();
        $boom = new LogicException('foreign failure');
        $foreign = new class ($transport, $boom) implements IDeferredDataSource {
            public int $fetches = 0;

            public function __construct(private readonly IStageTransport $transport, private readonly Throwable $boom)
            {
            }

            public function fetch(DataRequest $request): mixed
            {
                $this->fetches++;
                delay((float) $request->get('wait'));
                if ($request->get('fail')) {
                    throw $this->boom;
                }

                return 'foreign ' . $request->get('wait');
            }

            public function getCost(): int
            {
                return 0;
            }

            public function getTtl(): ?int
            {
                return null;
            }

            public function getTransport(): IStageTransport
            {
                return $this->transport;
            }
        };
        $results = $transport->fetchAll(self::pending([
            ['f', $foreign, ['wait' => '0.01', 'fail' => false]],
            ['f', $foreign, ['wait' => '0.01', 'fail' => true]],
        ]), null);
        $this->assertSame(['foreign 0.01', $boom], array_values($results), 'its value, or its exception as it is');

        $results = $transport->fetchAll(self::pending([['f', $foreign, ['wait' => '1', 'fail' => false]]]), CurlMultiTransport::now() + 0.03);
        $this->assertSame('The time limit of the resolution was reached before data source "f" answered.', array_values($results)[0]->getMessage(), 'the deadline applies');
        $this->assertSame(3, $foreign->fetches);
        delay(1.0);
    }

    public function testOutsideAFiberTheWaitRunsTheLoop(): void
    {
        $transport = new RevoltStageTransport();
        $ticks = 0;
        $ticker = EventLoop::repeat(0.01, static function () use (&$ticks): void {
            $ticks++;
        });
        try {
            $results = $transport->fetchAll(self::pending([['a', new RevoltDataSource((new ClosureProbe())->waiting(0.1), transport: $transport), []]]), null);
        } finally {
            EventLoop::cancel($ticker);
        }
        $this->assertSame([true], array_values($results));
        $this->assertGreaterThanOrEqual(5, $ticks, "the loop ran while the request waited ($ticks ticks of 10 ms in 100 ms)");
    }

    public function testInsideAFiberTheResolverSuspendsOnlyThatFiber(): void
    {
        $probe = new ClosureProbe();
        $transport = new RevoltStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('kyc', new RevoltDataSource($probe->waiting(0.1, static fn(array $p): string => $p['document'] === '111' ? 'verified' : 'pending'), transport: $transport))
            ->register('score', new RevoltDataSource($probe->waiting(0.1, 720), transport: $transport));
        $rule = Spec::external('kyc', ['document' => 'cpf'], Spec::equalTo('verified'))
            ->and(Spec::external('score', ['document' => 'cpf'], Spec::greaterThan(700)));
        $resolver = new DataResolver($registry);
        $events = [];

        $started = hrtime(true);
        [$first, $second] = await([
            async(static function () use ($resolver, $rule, &$events): bool {
                $verdict = $resolver->evaluate($rule, (object) ['cpf' => '111'])->isSatisfied;
                $events[] = 'resolution 111';
                return $verdict;
            }),
            async(static function () use ($resolver, $rule, &$events): bool {
                $verdict = $resolver->evaluate($rule, (object) ['cpf' => '222'])->isSatisfied;
                $events[] = 'resolution 222';
                return $verdict;
            }),
            async(static function () use (&$events): void {
                delay(0.03);
                $events[] = 'another fiber';
            }),
        ]);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame('another fiber', $events[0], 'the other Fiber ran while both resolutions waited');
        $this->assertSame(4, $probe->peak, 'the two resolutions shared the transport: four requests at the same time');
        $this->assertLessThan(350, $elapsed, sprintf('two resolutions of two 100 ms requests, together: %.0f ms', $elapsed));
    }

    public function testOneTransportCapsConcurrentResolutionsTogether(): void
    {
        $probe = new ClosureProbe();
        $transport = new RevoltStageTransport(2);
        $source = new RevoltDataSource($probe->waiting(0.03), transport: $transport);
        await([
            async(static fn(): array => $transport->fetchAll(self::pending([['a', $source, ['n' => 1]], ['a', $source, ['n' => 2]]]), null)),
            async(static fn(): array => $transport->fetchAll(self::pending([['a', $source, ['n' => 3]], ['a', $source, ['n' => 4]]]), null)),
        ]);
        $this->assertSame(4, $probe->completed);
        $this->assertSame(2, $probe->peak);
    }

    public function testAStageMixesRevoltAndCallableSources(): void
    {
        $order = [];
        $transport = new RevoltStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('callable', new CallableDataSource(static function (array $p) use (&$order): bool {
                $order[] = 'callable';
                return true;
            }))
            ->register('revolt.a', new RevoltDataSource(static function (array $p) use (&$order): bool {
                delay(0.01);
                $order[] = 'revolt.a';
                return true;
            }, transport: $transport))
            ->register('revolt.b', new RevoltDataSource(static function (array $p) use (&$order): bool {
                $order[] = 'revolt.b';
                return true;
            }, transport: $transport));
        $tree = Spec::external('callable', [], Spec::isTrue())
            ->and(Spec::external('revolt.a', [], Spec::isTrue()))
            ->and(Spec::external('revolt.b', [], Spec::isTrue()));

        $data = (new DataResolver($registry))->resolve($tree, (object) []);

        $this->assertSame(['revolt.b', 'revolt.a', 'callable'], $order, 'the transport first, its requests together (b did not wait for a); then the callable');
        $this->assertSame(1, $data->getStageCount());
        $this->assertSame(3, $data->getFetchCount());
        $this->assertTrue($data->evaluate($tree, (object) [])->isSatisfied);
    }

    /**
     * @param list<array{string, IDeferredDataSource, array<string, mixed>}> $requests
     * @return array<string, array{IDeferredDataSource, DataRequest}>
     */
    private static function pending(array $requests): array
    {
        $pending = [];
        foreach ($requests as [$name, $source, $parameters]) {
            $request = new DataRequest($name, $parameters);
            $pending[$request->getKey()] = [$source, $request];
        }

        return $pending;
    }

    /**
     * The enabled and referenced watchers of the event loop (timers and streams that keep it alive).
     *
     * @return list<string>
     */
    private static function activeWatchers(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), static fn(string $id): bool => EventLoop::isEnabled($id) && EventLoop::isReferenced($id)));
    }
}
