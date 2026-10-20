<?php

/**
 * A non-blocking HTTP server for the proof of gain and the HTTP parity (tests/Http) and the timing table
 * (tests/Support/timing-table.php). Adapted from the one of antevemus/alinq-revolt 1.0.0: the same server,
 * plus a `status` parameter for the failure cases of the parity.
 *
 * Started by the test as a child process: it listens on a free port of 127.0.0.1, prints "READY <port>" on
 * stdout and serves GET /item?ms=<n>&id=<id>&status=<code> by waiting n milliseconds without blocking
 * (Amp\delay()), so any number of requests wait at the same time, then answers {"id": <id>, "ms": <n>} with
 * the status code asked (200 by default; 100-599). It runs until the parent terminates it. No traffic leaves
 * the loopback interface.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Amp\Socket\BindContext;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\delay;

$server = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false, connectionLimitPerIp: 1000);
// TCP_NODELAY: without it, on a keep-alive connection (the Amp client keeps it; file_get_contents() closes it)
// Nagle's algorithm holds the body until the client acknowledges the headers, and the client delays that
// acknowledgement: measured on 2026-10-20 by antevemus/alinq-revolt, 44 ms per request (ms=0: 43.9 ms with the
// Amp client, 1.0 ms with file_get_contents()), 1.3 ms with it. The overhead belongs to the fixture, not to
// what is measured.
$server->expose('127.0.0.1:0', (new BindContext())->withTcpNoDelay());
$server->start(new ClosureRequestHandler(static function (Request $request): Response {
    parse_str($request->getUri()->getQuery(), $query);
    $ms = max(0, min(10000, (int) ($query['ms'] ?? 0)));
    $status = max(100, min(599, (int) ($query['status'] ?? 200)));
    delay($ms / 1000);

    return new Response($status, ['content-type' => 'application/json'], json_encode(['id' => (string) ($query['id'] ?? ''), 'ms' => $ms], JSON_THROW_ON_ERROR));
}), new DefaultErrorHandler());

$address = $server->getServers()[0]->getAddress();
fwrite(STDOUT, 'READY ' . $address->getPort() . PHP_EOL);
fflush(STDOUT);

EventLoop::run();
