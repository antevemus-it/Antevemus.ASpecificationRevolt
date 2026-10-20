<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Http;

use Amp\Cancellation;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\LatencyServer;
use Antevemus\ASpecificationRevolt\Tests\Support\ResolutionSummary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\delay;

/**
 * The HTTP leg of the parity of answers, against the local Amp server: the same trees
 * through the core's HttpDataSource, through RevoltDataSource with the Amp HTTP client and through
 * CallableDataSource with a blocking client, for the cases where HTTP applies: an answer (200), a status
 * outside 2xx (503: a failure in all three) and, between HTTP and Revolt, the source's own time limit.
 *
 * The same verdicts, evaluation errors, flags, reasons, codes, failed requests and stage and fetch counts, and
 * the same exception class (DataFetchException) and source name. The status outside 2xx is
 * DataFetchException::httpStatus() for the HTTP source (with getStatusCode()) and sourceFailed() for the two
 * closures, whose client raised on that status: the messages differ by nature and are not compared. The time
 * limit is the same factory on both sides (DataFetchException::timeout()), with the same message.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Http
 */
#[Group('http')]
final class HttpParityTest extends TestCase
{
    private static ?LatencyServer $server = null;

    private static ?string $unavailable = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$server = LatencyServer::start();
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

    public function testHttpRevoltAndCallableSourcesAnswerTheSame(): void
    {
        $server = self::$server;
        $this->assertNotNull($server);
        $registries = ['http' => self::http($server), 'revolt' => self::revolt($server), 'callable' => self::callable($server)];
        $compared = 0;
        foreach (self::trees() as $name => [$tree, $withCallable]) {
            foreach ([(object) ['id' => 'a-1'], (object) ['id' => 'b/2?x=..']] as $candidate) {
                foreach (['concurrent' => ResolutionPolicy::concurrent(), 'sequential' => ResolutionPolicy::sequential()] as $policyName => $policy) {
                    $expected = ResolutionSummary::of($registries['http'], $tree, $candidate, $policy);
                    $this->assertSame($expected, ResolutionSummary::of($registries['revolt'], $tree, $candidate, $policy), "$name, {$candidate->id}, $policyName: HTTP and Revolt");
                    $compared++;
                    if ($withCallable) {
                        $this->assertSame($expected, ResolutionSummary::of($registries['callable'], $tree, $candidate, $policy), "$name, {$candidate->id}, $policyName: HTTP and callable");
                        $compared++;
                    }
                }
            }
        }
        $this->assertSame(8 * 2 * 2 + 5 * 2 * 2, $compared);
        delay(0.001);
    }

    public function testTheFailuresOfEachSide(): void
    {
        $server = self::$server;
        $this->assertNotNull($server);
        $error = static function (DataSourceRegistry $registry, string $source): ?\Throwable {
            $data = (new DataResolver($registry))->resolve(Spec::external($source, ['id' => 'id'], Spec::alwaysTrue()), (object) ['id' => 'x']);
            return $data->getError(new DataRequest($source, ['id' => 'x']));
        };

        $http = $error(self::http($server), 'down');
        $revolt = $error(self::revolt($server), 'down');
        $this->assertInstanceOf(DataFetchException::class, $http);
        $this->assertSame(503, $http->getStatusCode());
        $this->assertSame('Data source "down" answered with HTTP status 503.', $http->getMessage());
        $this->assertSame('Data source "down" failed (RuntimeException).', $revolt?->getMessage());

        $this->assertSame('Data source "slow" did not answer within its time limit of 0.100 s.', $error(self::http($server), 'slow')?->getMessage());
        $this->assertSame('Data source "slow" did not answer within its time limit of 0.100 s.', $error(self::revolt($server), 'slow')?->getMessage());
        delay(0.001);
    }

    /** @return array<string, array{ISpecification, bool}> the tree, and whether the callable side takes part */
    private static function trees(): array
    {
        $ok = static fn(): ISpecification => Spec::external('item', ['id' => 'id'], Spec::equalTo('a-1'))->withCode('ITEM');
        $down = static fn(): ISpecification => Spec::external('down', ['id' => 'id'], Spec::alwaysTrue())->withCode('DOWN');
        $slow = static fn(): ISpecification => Spec::external('slow', ['id' => 'id'], Spec::alwaysTrue())->withCode('SLOW');

        return [
            'value' => [$ok(), true],
            'status 503' => [$down(), true],
            'value and 503' => [$ok()->and($down()), true],
            'value or 503' => [$ok()->or($down()), true],
            'not 503' => [Spec::not($down()), true],
            'time limit' => [$slow(), false],
            'value or time limit' => [$ok()->or($slow()), false],
            'everything' => [$ok()->and(Spec::not($down()))->or($slow()), false],
        ];
    }

    private static function http(LatencyServer $server): DataSourceRegistry
    {
        $parse = static fn(int $status, array $headers, string $body): string => json_decode($body, true, 512, JSON_THROW_ON_ERROR)['id'];

        return (new DataSourceRegistry())
            ->register('item', new HttpDataSource($server->baseUrl(), '/item?ms=0&id={id}', parse: $parse))
            ->register('down', new HttpDataSource($server->baseUrl(), '/item?ms=0&id={id}&status=503', parse: $parse))
            ->register('slow', new HttpDataSource($server->baseUrl(), '/item?ms=1000&id={id}', parse: $parse, timeout: 0.1));
    }

    private static function revolt(LatencyServer $server): DataSourceRegistry
    {
        $transport = new RevoltStageTransport();
        $get = static fn(int $ms, int $status, float $timeout = 5.0): RevoltDataSource => new RevoltDataSource(
            static fn(array $p, DataRequest $r, Cancellation $c): string => json_decode($server->asyncGet($ms, $p['id'], $c, $status), true, 512, JSON_THROW_ON_ERROR)['id'],
            timeout: $timeout,
            transport: $transport,
        );

        return (new DataSourceRegistry())->register('item', $get(0, 200))->register('down', $get(0, 503))->register('slow', $get(1000, 200, 0.1));
    }

    private static function callable(LatencyServer $server): DataSourceRegistry
    {
        $get = static fn(int $status): CallableDataSource => new CallableDataSource(
            static fn(array $p): string => json_decode($server->blockingGet(0, $p['id'], $status), true, 512, JSON_THROW_ON_ERROR)['id'],
        );

        return (new DataSourceRegistry())->register('item', $get(200))->register('down', $get(503));
    }
}
