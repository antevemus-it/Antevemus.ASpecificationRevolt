<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Http;

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\LatencyServer;
use Antevemus\ASpecificationRevolt\Tests\Support\StageBench;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The proof of gain, against a non-blocking Amp HTTP server started on 127.0.0.1 in a
 * child process (tests/Support/latency-server.php): one resolution of N external data leaves, each a
 * distinct request of GET /item?ms=200, all in one stage of the DataResolver (concurrent policy).
 *
 * - CallableDataSource with a blocking client (file_get_contents()): the stage runs the callables one after
 *   the other, about N × 200 ms.
 * - RevoltDataSource with the Amp HTTP client inside the closure: the requests of the stage wait together,
 *   about ⌈N / maxConcurrency⌉ × 200 ms (200 ms with a limit of 16, 400 ms for 16 sources with the default 8).
 * - The core's HttpDataSource (curl_multi) for the same calls: about 200 ms, the reference.
 * - The SAME blocking client inside a RevoltDataSource still takes about N × 200 ms, and a closure that only
 *   computes gains nothing: a Fiber overlaps waits on non-blocking I/O, never a blocking call nor CPU work.
 *
 * The bounds are generous (the CI machine may be slow) and every failure message carries the measured times.
 * When the server cannot bind or start in the environment the tests are skipped with the reason, never
 * passed silently. The table of the documentation comes from tests/Support/timing-table.php.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Http
 */
#[Group('http')]
final class ProofOfGainTest extends TestCase
{
    private static ?LatencyServer $server = null;

    private static ?string $unavailable = null;

    /** @var array<string, float> measured milliseconds, by case */
    private static array $measured = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$server = LatencyServer::start();
            // warm-up: the first request of each client pays for its setup, not for the latency under test
            self::$server->blockingGet(0, 'warm-up');
            self::$server->asyncGet(0, 'warm-up');
        } catch (RuntimeException $e) {
            self::$unavailable = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('The local Amp HTTP server could not start in this environment: ' . self::$unavailable);
        }
    }

    public function testCallableSourcesWithABlockingClientWaitForEachAnswerInTurn(): void
    {
        $source = StageBench::callableBlocking($this->server());
        foreach ([4 => 700, 16 => 2500] as $items => $bound) {
            $ms = StageBench::resolve($source, $items);
            self::$measured["callable-$items"] = $ms;
            $this->assertGreaterThan($bound, $ms, sprintf('CallableDataSource, blocking client, %d sources: %.0f ms', $items, $ms));
        }
    }

    public function testRevoltSourcesWithTheAmpClientOverlapTheWaits(): void
    {
        $server = $this->server();
        foreach ([[16, 16, 180, 600], [16, 8, 380, 800], [4, 8, 180, 500], [1, 8, 180, 450]] as [$items, $limit, $min, $max]) {
            $server->resetClient();
            $ms = StageBench::resolve(StageBench::revoltAmp($server, new RevoltStageTransport($limit)), $items);
            self::$measured["revolt-$limit-$items"] = $ms;
            $this->assertLessThan($max, $ms, sprintf('RevoltDataSource, Amp client, %d sources, maxConcurrency %d: %.0f ms', $items, $limit, $ms));
            $this->assertGreaterThan($min, $ms, sprintf('RevoltDataSource, %d sources, maxConcurrency %d, cannot beat %d round(s) of %d ms: %.0f ms', $items, $limit, (int) ceil($items / $limit), StageBench::LATENCY_MS, $ms));
        }
    }

    public function testTheCoreHttpBatchOverlapsTheSameCalls(): void
    {
        $ms = StageBench::resolve(StageBench::http($this->server()), 16);
        self::$measured['http-16'] = $ms;
        $this->assertLessThan(600, $ms, sprintf('HttpDataSource (curl_multi), 16 sources: %.0f ms', $ms));
    }

    #[Depends('testCallableSourcesWithABlockingClientWaitForEachAnswerInTurn')]
    #[Depends('testRevoltSourcesWithTheAmpClientOverlapTheWaits')]
    public function testRevoltSixteenIsAtLeastThreeTimesFasterThanCallables(): void
    {
        $this->assertLessThan(self::$measured['callable-16'] / 3, self::$measured['revolt-16-16'], sprintf('callable %.0f ms, Revolt (16) %.0f ms', self::$measured['callable-16'], self::$measured['revolt-16-16']));
    }

    public function testABlockingClientInsideARevoltSourceGainsNothing(): void
    {
        $server = $this->server();
        $ms = StageBench::resolve(StageBench::revoltBlocking($server, new RevoltStageTransport(16)), 16);
        self::$measured['revolt-blocking-16'] = $ms;
        $this->assertGreaterThan(2500, $ms, sprintf('RevoltDataSource with a blocking client, 16 sources, maxConcurrency 16: %.0f ms; a Fiber does not overlap a blocking call', $ms));
    }

    public function testAClosureThatOnlyComputesGainsNothing(): void
    {
        $compute = StageBench::computing(StageBench::calibrate(0.025));
        $sequential = StageBench::resolve(new CallableDataSource($compute), 8);
        $fibers = StageBench::resolve(new RevoltDataSource($compute, transport: new RevoltStageTransport(16)), 8);
        self::$measured['cpu-callable-8'] = $sequential;
        self::$measured['cpu-revolt-8'] = $fibers;
        $this->assertGreaterThan(8 * 25 * 0.6, $sequential, sprintf('8 closures of about 25 ms of CPU, callable: %.0f ms', $sequential));
        $this->assertGreaterThan($sequential * 0.7, $fibers, sprintf('8 closures of about 25 ms of CPU: callable %.0f ms, Revolt %.0f ms; the Fibers take turns on the one thread', $sequential, $fibers));
    }

    private function server(): LatencyServer
    {
        $server = self::$server;
        $this->assertNotNull($server);

        return $server;
    }
}
