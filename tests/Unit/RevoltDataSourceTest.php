<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Unit;

use Amp\Cancellation;
use Amp\Future;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\InMemoryDataCache;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\ClosureProbe;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * RevoltDataSource: the argument checks, the getters, the arguments of the closure (the arity rule of
 * CallableDataSource plus the Cancellation), a returned Amp\Future, the unitary fetch() (outside a scope, as a
 * value or a thrown failure; inside a Fiber, suspending it), the resolver's sequential policy and the cache.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Unit
 */
final class RevoltDataSourceTest extends TestCase
{
    public function testANegativeTtlIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The TTL of a data source cannot be negative.');
        new RevoltDataSource(static fn(array $p): bool => true, ttl: -1);
    }

    /** @return array<string, array{float}> */
    public static function invalidTimeouts(): array
    {
        return ['zero' => [0.0], 'negative' => [-0.5], 'infinite' => [INF], 'not a number' => [NAN]];
    }

    #[DataProvider('invalidTimeouts')]
    public function testANonPositiveOrNonFiniteTimeoutIsRefused(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The time limit of a Revolt data source must be a positive number of seconds.');
        new RevoltDataSource(static fn(array $p): bool => true, timeout: $timeout);
    }

    public function testTheGetters(): void
    {
        $source = new RevoltDataSource(static fn(array $p): bool => true);
        $this->assertSame(0, $source->getCost());
        $this->assertNull($source->getTtl());
        $this->assertSame(5.0, $source->getTimeout());
        $this->assertSame(RevoltStageTransport::shared(), $source->getTransport());

        $transport = new RevoltStageTransport(3);
        $source = new RevoltDataSource(static fn(array $p): bool => true, cost: 7, ttl: 0, timeout: 0.001, transport: $transport);
        $this->assertSame(7, $source->getCost());
        $this->assertSame(0, $source->getTtl(), 'a TTL of 0 is accepted (never cached)');
        $this->assertSame(0.001, $source->getTimeout());
        $this->assertSame($transport, $source->getTransport());
    }

    public function testTheClosureReceivesTheParametersTheRequestAndACancellationByItsArity(): void
    {
        $request = new DataRequest('kyc', ['document' => '111', 'branch' => 7]);
        $one = new RevoltDataSource(static fn(array $p): array => $p);
        $this->assertSame(['branch' => 7, 'document' => '111'], $one->fetch($request), 'fn(array $p): the parameters, sorted by name');

        $two = new RevoltDataSource(function (array $p, DataRequest $r): array {
            return [func_num_args(), $r];
        });
        $this->assertSame([2, $request], $two->fetch($request), 'two parameters: the parameters and the request, as CallableDataSource');

        $three = new RevoltDataSource(function (array $p, DataRequest $r, Cancellation $c): array {
            return [func_num_args(), $r, $c->isRequested()];
        });
        $this->assertSame([3, $request, false], $three->fetch($request), 'a third parameter: the Cancellation of the request');

        $variadic = new RevoltDataSource(static fn(mixed ...$arguments): int => count($arguments));
        $this->assertSame(3, $variadic->fetch($request), 'a variadic closure receives the three');

        $rest = new RevoltDataSource(static fn(array $p, mixed ...$rest): int => count($rest));
        $this->assertSame(2, $rest->fetch($request), 'variadic after the parameters: the request and the cancellation');
    }

    public function testAReturnedFutureIsAwaited(): void
    {
        $source = new RevoltDataSource(static fn(array $p): Future => async(static function () use ($p): int {
            delay(0.01);
            return $p['n'] + 1;
        }));
        $this->assertSame(42, $source->fetch(new DataRequest('answer', ['n' => 41])));

        $failing = new RevoltDataSource(static fn(array $p): Future => async(static fn(): never => throw new RuntimeException('async failure')));
        try {
            $failing->fetch(new DataRequest('answer', ['n' => 41]));
            $this->fail('no exception');
        } catch (DataFetchException $e) {
            $this->assertSame('Data source "answer" failed (RuntimeException).', $e->getMessage(), 'the failure of the Future is the failure of the closure');
            $this->assertSame('async failure', $e->getPrevious()?->getMessage());
        }

        $late = new RevoltDataSource(static fn(array $p): Future => async(static function (): int {
            delay(1.0);
            return 1;
        }), timeout: 0.02);
        try {
            $late->fetch(new DataRequest('answer', []));
            $this->fail('no exception');
        } catch (DataFetchException $e) {
            $this->assertSame('Data source "answer" did not answer within its time limit of 0.020 s.', $e->getMessage(), 'a Future past the time limit');
        }
        delay(1.0);
    }

    public function testFetchThrowsTheMappedFailure(): void
    {
        $failing = new RevoltDataSource(static fn(array $p): never => throw new RuntimeException("no account {$p['account']}"));
        try {
            $failing->fetch(new DataRequest('ledger', ['account' => 'A-1']));
            $this->fail('no exception');
        } catch (DataFetchException $e) {
            $this->assertSame('Data source "ledger" failed (RuntimeException).', $e->getMessage());
            $this->assertStringNotContainsString('A-1', $e->getMessage());
        }

        $slow = new RevoltDataSource((new ClosureProbe())->waiting(1.0), timeout: 0.02);
        $started = hrtime(true);
        try {
            $slow->fetch(new DataRequest('ledger', []));
            $this->fail('no exception');
        } catch (DataFetchException $e) {
            $this->assertSame('Data source "ledger" did not answer within its time limit of 0.020 s.', $e->getMessage());
        }
        $this->assertLessThan(300, (hrtime(true) - $started) / 1e6);

        $pending = new DataPendingException('ledger', 'T-9');
        $async = new RevoltDataSource(static fn(array $p): never => throw $pending);
        try {
            $async->fetch(new DataRequest('ledger', []));
            $this->fail('no exception');
        } catch (DataPendingException $e) {
            $this->assertSame($pending, $e, 'a pending result passes through');
        }
        delay(0.001);
    }

    public function testFetchGoesThroughTheSourcesTransport(): void
    {
        $probe = new ClosureProbe();
        $transport = new RevoltStageTransport(1);
        $source = new RevoltDataSource($probe->waiting(0.05), transport: $transport);
        $started = hrtime(true);
        $values = await([
            async(static fn(): mixed => $source->fetch(new DataRequest('one', ['n' => 1]))),
            async(static fn(): mixed => $source->fetch(new DataRequest('one', ['n' => 2]))),
        ]);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $this->assertSame([true, true], $values);
        $this->assertSame(1, $probe->peak, 'two unitary fetches through a transport of one slot: one after the other');
        $this->assertGreaterThan(90, $elapsed, sprintf('%.0f ms', $elapsed));
    }

    public function testOutsideAScopeTheLeafTakesTheUnitaryFetch(): void
    {
        $probe = new ClosureProbe();
        $registry = (new DataSourceRegistry())->register('kyc', new RevoltDataSource($probe->waiting(0.01, static fn(array $p): string => $p['document'] === '111' ? 'verified' : 'pending')));
        DataSourceRegistry::setDefault($registry);
        try {
            $leaf = Spec::external('kyc', ['document' => 'cpf'], Spec::equalTo('verified'));
            $this->assertTrue($leaf->evaluate((object) ['cpf' => '111'])->isSatisfied);
            $this->assertFalse($leaf->evaluate((object) ['cpf' => '222'])->isSatisfied);
        } finally {
            DataSourceRegistry::setDefault(null);
        }
        $this->assertSame(['111', '222'], array_map(static fn(DataRequest $r): string => (string) $r->get('document'), $probe->requests));
    }

    public function testInsideAFiberFetchSuspendsOnlyThatFiber(): void
    {
        $source = new RevoltDataSource((new ClosureProbe())->waiting(0.05, 'value'));
        $events = [];
        await([
            async(static function () use ($source, &$events): void {
                $events[] = 'fetch ' . $source->fetch(new DataRequest('x', []));
            }),
            async(static function () use (&$events): void {
                delay(0.01);
                $events[] = 'other';
            }),
        ]);
        $this->assertSame(['other', 'fetch value'], $events);
    }

    public function testTheSequentialPolicyAndTheCache(): void
    {
        $probe = new ClosureProbe();
        $transport = new RevoltStageTransport();
        $registry = (new DataSourceRegistry())
            ->register('a', new RevoltDataSource($probe->waiting(0.01, true), ttl: 60, transport: $transport))
            ->register('b', new RevoltDataSource($probe->waiting(0.01, false), transport: $transport))
            ->register('c', new RevoltDataSource($probe->waiting(0.01, true), transport: $transport));
        $tree = Spec::external('a', [], Spec::isTrue())->and(Spec::external('b', [], Spec::isTrue()))->and(Spec::external('c', [], Spec::isTrue()));
        $resolver = new DataResolver($registry, new InMemoryDataCache());

        $data = $resolver->resolve($tree, (object) [], ResolutionPolicy::sequential());
        $this->assertSame(0, $probe->started, 'sequential: nothing fetched beforehand');
        $this->assertFalse($data->isSatisfiedBy($tree, (object) []));
        $this->assertSame(['a', 'b'], array_map(static fn(DataRequest $r): string => $r->source, $probe->requests), 'each leaf when reached; c short-circuited by isSatisfiedBy()');
        $this->assertSame(2, $data->getStageCount());

        $data = $resolver->resolve($tree, (object) []);
        $this->assertSame(1, $data->getCacheHitCount(), 'a, with a TTL, answered by the cache');
        $this->assertSame(['a', 'b', 'b', 'c'], array_map(static fn(DataRequest $r): string => $r->source, $probe->requests));
        $this->assertSame(1, $data->getStageCount());
    }
}
