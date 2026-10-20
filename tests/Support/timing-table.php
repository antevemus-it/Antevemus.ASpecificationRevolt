<?php

/**
 * Measures the timing table of the documentation (docs/<language>/quality/tests.md and the README):
 *
 *   XDEBUG_MODE=off php tests/Support/timing-table.php [repetitions]
 *
 * Starts tests/Support/latency-server.php on 127.0.0.1 and, for 1, 4 and 16 sources of GET /item?ms=200 (one
 * resolution of that many external data leaves, each a distinct request, in one stage of the DataResolver),
 * runs CallableDataSource with a blocking client (file_get_contents()), RevoltDataSource with the Amp HTTP
 * client at maxConcurrency 8 (the default) and 16, the core's HttpDataSource (curl_multi), and
 * RevoltDataSource with the same blocking client (maxConcurrency 16). Then 8 closures that only compute for
 * about 25 ms each, as CallableDataSource and as RevoltDataSource (maxConcurrency 16). Each cell is the median
 * of the repetitions (default 3), in milliseconds, after one warm-up request per client; each run starts with
 * a new Amp client (a new connection pool), its connection setup included. Prints two Markdown tables and the
 * PHP version.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\LatencyServer;
use Antevemus\ASpecificationRevolt\Tests\Support\StageBench;

$repetitions = max(1, (int) ($argv[1] ?? 3));
$server = LatencyServer::start();
$server->blockingGet(0, 'warm-up');
$server->asyncGet(0, 'warm-up');

$columns = [
    'CallableDataSource, blocking' => static fn(): CallableDataSource => StageBench::callableBlocking($server),
    'RevoltDataSource (8), Amp client' => static fn(): RevoltDataSource => StageBench::revoltAmp($server, new RevoltStageTransport(8)),
    'RevoltDataSource (16), Amp client' => static fn(): RevoltDataSource => StageBench::revoltAmp($server, new RevoltStageTransport(16)),
    'HttpDataSource (curl_multi)' => static fn() => StageBench::http($server),
    'RevoltDataSource (16), blocking' => static fn(): RevoltDataSource => StageBench::revoltBlocking($server, new RevoltStageTransport(16)),
];

$median = static function (array $values): float {
    sort($values);
    return $values[intdiv(count($values), 2)];
};
$measure = static function (Closure $source, int $items) use ($server, $repetitions, $median): string {
    $times = [];
    for ($r = 0; $r < $repetitions; $r++) {
        $server->resetClient();
        $times[] = StageBench::resolve($source(), $items);
    }
    return number_format($median($times), 0, '.', ' ') . ' ms';
};

echo '| Sources × ' . StageBench::LATENCY_MS . ' ms | ' . implode(' | ', array_keys($columns)) . " |\n";
echo '| ---: |' . str_repeat(' ---: |', count($columns)) . "\n";
foreach ([1, 4, 16] as $items) {
    $cells = [];
    foreach ($columns as $source) {
        $cells[] = $measure($source, $items);
    }
    echo "| $items | " . implode(' | ', $cells) . " |\n";
}

$compute = StageBench::computing(StageBench::calibrate(0.025));
echo "\n| 8 closures of about 25 ms of CPU | CallableDataSource | RevoltDataSource (16) |\n| ---: | ---: | ---: |\n";
echo '| 8 | ' . $measure(static fn(): CallableDataSource => new CallableDataSource($compute), 8) . ' | ' . $measure(static fn(): RevoltDataSource => new RevoltDataSource($compute, transport: new RevoltStageTransport(16)), 8) . " |\n";
echo "\nPHP " . PHP_VERSION . ", median of $repetitions runs per cell\n";
$server->stop();
