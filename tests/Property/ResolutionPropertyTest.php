<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Property;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\CountingStageTransport;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

use function Amp\delay;

/**
 * Properties of the resolution through RevoltDataSource, 300 seeded random cases (fixed seed): random trees
 * (and, or, not, depth up to 3) of Spec::external() leaves over 3-6 random sources (true or false by
 * candidate, one in six failing), each either a RevoltDataSource on one of 1-3 transports (each with a random
 * limit of 1-8) or a CallableDataSource, with random costs 0-2, and a random candidate:
 *
 * - the verdict (isSatisfied, isError) is the same through the Revolt sources and through callable sources
 *   with the same answers, under concurrent(), tiered() and sequential(), and the same across the policies;
 * - getFetchCount() equals the number of distinct requests of the resolution (each key fetched once);
 * - each transport's fetchAll() is called at most once per stage: once under concurrent() (one stage) when
 *   the tree has requests on it; under tiered() each call holds the keys of one cost, never two calls of a
 *   transport for the same cost, and no more calls than getStageCount().
 *
 * The transports are counted through CountingStageTransport, a decorating IStageTransport that delegates to
 * a RevoltStageTransport (final).
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Property
 */
final class ResolutionPropertyTest extends TestCase
{
    private const CASES = 300;

    private const SEED = 45045;

    public function testRevoltAndCallableSourcesGiveTheSameVerdictsAndEachTransportIsCalledOncePerStage(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $mismatches = [];
        $checks = 0;
        $stats = ['two transports' => 0, 'tiered skipped' => 0, 'errors' => 0, 'multi-stage tiered' => 0];

        for ($case = 0; $case < self::CASES; $case++) {
            $transports = [];
            for ($k = $random->getInt(1, 3), $j = 0; $j < $k; $j++) {
                $transports[] = new CountingStageTransport(new RevoltStageTransport($random->getInt(1, 8)));
            }
            $revolt = new DataSourceRegistry();
            $callable = new DataSourceRegistry();
            $costOf = [];
            $transportOf = [];
            $n = $random->getInt(3, 6);
            for ($s = 0; $s < $n; $s++) {
                $answers = [];
                foreach (['111', '222'] as $cpf) {
                    $answers[$cpf] = $random->getInt(0, 5) === 0 ? 'fail' : $random->getInt(0, 1) === 1;
                }
                $answer = static function (array $p) use ($answers): bool {
                    $value = $answers[$p['document']];
                    if ($value === 'fail') {
                        throw new RuntimeException('source down');
                    }
                    return $value;
                };
                $costOf["s$s"] = $cost = $random->getInt(0, 2);
                $callable->register("s$s", new CallableDataSource($answer, $cost));
                if ($random->getInt(0, 3) === 0) {
                    $revolt->register("s$s", new CallableDataSource($answer, $cost));
                    continue;
                }
                $index = $random->getInt(0, count($transports) - 1);
                $transportOf["s$s"] = $index;
                $wait = $random->getInt(0, 2) / 1000;
                $revolt->register("s$s", $transports[$index]->source(new RevoltDataSource(static function (array $p) use ($answer, $wait): bool {
                    delay($wait);
                    return $answer($p);
                }, cost: $cost, transport: $transports[$index]->inner)));
            }
            $tree = self::tree($random, 3, $n);
            $candidate = (object) ['cpf' => $random->getInt(0, 1) === 1 ? '111' : '222'];
            $calls = static fn(): array => array_map(static fn(CountingStageTransport $t): int => count($t->calls), $transports);

            $verdicts = [];
            foreach (['concurrent' => ResolutionPolicy::concurrent(), 'tiered' => ResolutionPolicy::tiered(), 'sequential' => ResolutionPolicy::sequential()] as $name => $policy) {
                $before = $calls();
                $data = (new DataResolver($revolt))->resolve($tree, $candidate, $policy);
                $result = $data->evaluate($tree, $candidate);
                $expected = (new DataResolver($callable))->evaluate($tree, $candidate, $policy);
                $verdicts[$name] = $result->isSatisfied;
                $checks += 2 + count($transports);
                if ([$result->isSatisfied, $result->isError] !== [$expected->isSatisfied, $expected->isError]) {
                    $mismatches[] = sprintf('#%d %s: Revolt [%s, %s], callable [%s, %s]', $case, $name, var_export($result->isSatisfied, true), var_export($result->isError, true), var_export($expected->isSatisfied, true), var_export($expected->isError, true));
                }
                $stats['errors'] += $result->isError ? 1 : 0;
                $requests = $data->getRequests();
                $fetched = count($requests) - count($data->getSkippedRequests());
                if ($data->getFetchCount() !== $fetched) {
                    $mismatches[] = sprintf('#%d %s: getFetchCount() %d for %d distinct requests fetched', $case, $name, $data->getFetchCount(), $fetched);
                }
                $used = array_unique(array_values(array_filter(array_map(static fn(DataRequest $r): ?int => $transportOf[$r->source] ?? null, $requests), static fn(?int $i): bool => $i !== null)));
                foreach ($transports as $index => $transport) {
                    $made = array_slice($transport->calls, $before[$index]);
                    if ($name === 'concurrent' && count($made) !== (in_array($index, $used, true) ? 1 : 0)) {
                        $mismatches[] = sprintf('#%d concurrent: transport %d called %d times', $case, $index, count($made));
                    }
                    if ($name === 'tiered') {
                        $costs = array_map(static fn(array $sources): array => array_values(array_unique(array_map(static fn(string $s): int => $costOf[$s], $sources))), $made);
                        $single = array_filter($costs, static fn(array $c): bool => count($c) === 1);
                        $distinct = array_unique(array_map(static fn(array $c): int => $c[0], $single));
                        if (count($single) !== count($made) || count($distinct) !== count($made) || count($made) > $data->getStageCount()) {
                            $mismatches[] = sprintf('#%d tiered: transport %d called %d times over costs %s in %d stages', $case, $index, count($made), json_encode($costs), $data->getStageCount());
                        }
                        $stats['multi-stage tiered'] += count($made) > 1 ? 1 : 0;
                    }
                }
                if ($name === 'concurrent') {
                    $stats['two transports'] += count($used) >= 2 ? 1 : 0;
                }
                if ($name === 'tiered') {
                    $stats['tiered skipped'] += $data->getSkippedRequests() !== [] ? 1 : 0;
                }
            }
            $checks++;
            if (count(array_unique($verdicts)) !== 1) {
                $mismatches[] = sprintf('#%d: the verdict differs across the policies %s', $case, json_encode($verdicts));
            }
        }

        // Every check of the loop counts as an assertion; the ones that failed are listed here
        $this->addToAssertionCount($checks);
        $this->assertSame([], $mismatches, implode("\n", array_slice($mismatches, 0, 20)));
        // The generator reaches the interesting cases. Measured with the seed 45045: 63 cases with two transports
        // or more in one stage, 59 tiered resolutions that skipped requests, 174 evaluation errors, 44 transports
        // called in two tiered stages; 3 930 checks. Required: a few of each.
        $this->assertGreaterThan(30, $stats['two transports'], json_encode($stats, JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(30, $stats['tiered skipped'], json_encode($stats, JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(30, $stats['errors'], json_encode($stats, JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(10, $stats['multi-stage tiered'], json_encode($stats, JSON_THROW_ON_ERROR));
    }

    private static function tree(Randomizer $random, int $depth, int $sources): ISpecification
    {
        if ($depth === 0 || $random->getInt(0, 3) === 0) {
            return Spec::external('s' . $random->getInt(0, $sources - 1), ['document' => 'cpf'], $random->getInt(0, 1) === 1 ? Spec::isTrue() : Spec::isFalse());
        }

        return match ($random->getInt(0, 2)) {
            0 => self::tree($random, $depth - 1, $sources)->and(self::tree($random, $depth - 1, $sources)),
            1 => self::tree($random, $depth - 1, $sources)->or(self::tree($random, $depth - 1, $sources)),
            default => Spec::not(self::tree($random, $depth - 1, $sources)),
        };
    }
}
