<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Support;

use Amp\Cancellation;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\IDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use RuntimeException;

/**
 * The bench of the proof of gain (tests/Http/ProofOfGainTest.php) and of the timing table
 * (tests/Support/timing-table.php): one resolution of N external data leaves over one source, each leaf a
 * distinct request (GET /item?ms=200&id=item-<i> against the local server), all in one stage of the
 * DataResolver under the concurrent policy; the sources that fetch it; and a closure that only computes.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */
final class StageBench
{
    /** The latency of every request of the bench, in milliseconds. */
    public const LATENCY_MS = 200;

    /** One resolution of $items leaves over the source, each a distinct request; checks every answer; returns the milliseconds. */
    public static function resolve(IDataSource $source, int $items): float
    {
        $registry = (new DataSourceRegistry())->register('item', $source);
        $tree = Spec::alwaysTrue();
        $candidate = new \stdClass();
        for ($i = 1; $i <= $items; $i++) {
            $candidate->{"id$i"} = "item-$i";
            $tree = $tree->and(Spec::external('item', ['id' => "id$i"], Spec::equalTo("item-$i")));
        }
        $started = hrtime(true);
        $data = (new DataResolver($registry))->resolve($tree, $candidate);
        $ms = (hrtime(true) - $started) / 1e6;
        $result = $data->evaluate($tree, $candidate);
        if (!$result->isSatisfied || $data->getFetchCount() !== $items || $data->getStageCount() !== 1) {
            throw new RuntimeException(sprintf('unexpected resolution: satisfied %s, %d fetches, %d stages, %s', var_export($result->isSatisfied, true), $data->getFetchCount(), $data->getStageCount(), $result->exception?->getMessage() ?? 'no exception'));
        }

        return $ms;
    }

    /** @param array<string, string> $headers */
    public static function parse(int $status, array $headers, string $body): string
    {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public static function callableBlocking(LatencyServer $server): CallableDataSource
    {
        return new CallableDataSource(static fn(array $p): string => self::parse(200, [], $server->blockingGet(self::LATENCY_MS, $p['id'])));
    }

    public static function revoltAmp(LatencyServer $server, RevoltStageTransport $transport): RevoltDataSource
    {
        return new RevoltDataSource(
            static fn(array $p, DataRequest $request, Cancellation $cancellation): string => self::parse(200, [], $server->asyncGet(self::LATENCY_MS, $p['id'], $cancellation)),
            transport: $transport,
        );
    }

    /** The same blocking client inside a RevoltDataSource: the Fibers take turns, nothing overlaps. */
    public static function revoltBlocking(LatencyServer $server, RevoltStageTransport $transport): RevoltDataSource
    {
        return new RevoltDataSource(static fn(array $p): string => self::parse(200, [], $server->blockingGet(self::LATENCY_MS, $p['id'])), transport: $transport);
    }

    /** The core's HTTP source for the same calls (`curl_multi`). */
    public static function http(LatencyServer $server): HttpDataSource
    {
        return new HttpDataSource($server->baseUrl(), '/item?ms=' . self::LATENCY_MS . '&id={id}', parse: self::parse(...));
    }

    /**
     * A closure that only computes: the loop of calibrate(), $iterations times, then the id of the request.
     *
     * @return \Closure(array<string, string|int|float|bool>): string
     */
    public static function computing(int $iterations): \Closure
    {
        return static function (array $p) use ($iterations): string {
            $x = 0;
            for ($i = 0; $i < $iterations; $i++) {
                $x = ($x + $i * 31) % 1000003;
            }
            return $p['id'] . ($x >= 0 ? '' : '?');
        };
    }

    /**
     * The iterations of the computing loop that take about $seconds on this machine: the loop is run with twice
     * the iterations until one run takes at least 50 ms, and scaled from that run (a short sample, of a
     * millisecond, measured about two thirds of the real cost).
     */
    public static function calibrate(float $seconds): int
    {
        for ($iterations = 100000; ; $iterations *= 2) {
            $compute = self::computing($iterations);
            $started = hrtime(true);
            $compute(['id' => 'calibration']);
            $elapsed = (hrtime(true) - $started) / 1e9;
            if ($elapsed >= 0.05) {
                return max(1000, (int) ($iterations * $seconds / $elapsed));
            }
        }
    }
}
