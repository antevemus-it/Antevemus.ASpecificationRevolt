<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Support;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use RuntimeException;

/**
 * The child process of latency-server.php, and the clients of the measurements: a blocking one
 * (file_get_contents(), inside a CallableDataSource) and the Amp HTTP client (non-blocking, inside the closure
 * of a RevoltDataSource). Adapted from the one of antevemus/alinq-revolt 1.0.0: both clients also take the
 * status code the server must answer, and fail on a status outside 2xx, as an HttpDataSource does.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */
final class LatencyServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private HttpClient $client;

    private function __construct(public readonly int $port)
    {
        $this->client = HttpClientBuilder::buildDefault();
    }

    /**
     * Starts the server and waits up to 10 s for its "READY <port>" line.
     *
     * @throws RuntimeException When the process cannot start or does not report a port (it cannot bind)
     */
    public static function start(): self
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/latency-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off'] + getenv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start tests/Support/latency-server.php');
        }
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 10.0;
        $output = '';
        while (microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]);
            if (preg_match('/^READY (\d+)$/m', $output, $m) === 1) {
                $server = new self((int) $m[1]);
                $server->process = $process;
                $server->pipes = $pipes;
                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(10000);
        }
        $errors = (string) stream_get_contents($pipes[2]);
        proc_terminate($process);
        throw new RuntimeException('tests/Support/latency-server.php did not report a port: ' . trim(preg_replace('/^Cannot load Xdebug.*$/m', '', $errors . $output) ?? ''));
    }

    /**
     * A new Amp client, with an empty connection pool: called before each measurement, so no measurement
     * reuses a keep-alive connection the server may have closed while idle (its HTTP/1.1 timeout is 15 s;
     * found by the timing table, whose blocking columns leave the pool idle for about 20 s).
     */
    public function resetClient(): void
    {
        $this->client = HttpClientBuilder::buildDefault();
    }

    public function url(int $ms, int|string $id, int $status = 200): string
    {
        return sprintf('http://127.0.0.1:%d/item?ms=%d&id=%s&status=%d', $this->port, $ms, rawurlencode((string) $id), $status);
    }

    /** The base URL of an HttpDataSource against this server. */
    public function baseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    /**
     * A blocking GET: the PHP stream wrapper waits for the whole answer and the event loop stands still.
     *
     * @throws RuntimeException On a status outside 2xx (the body is not read into the message)
     */
    public function blockingGet(int $ms, int|string $id, int $status = 200): string
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = file_get_contents($this->url($ms, $id, $status), false, $context);
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if ($body === false || preg_match('#^HTTP/\S+ (\d{3})#', (string) ($headers[0] ?? ''), $m) !== 1) {
            throw new RuntimeException("GET item $id failed");
        }
        if ((int) $m[1] < 200 || (int) $m[1] > 299) {
            throw new RuntimeException("GET item $id answered with HTTP status {$m[1]}");
        }

        return $body;
    }

    /**
     * A non-blocking GET with the Amp HTTP client: the Fiber is suspended while the answer travels.
     *
     * @throws RuntimeException On a status outside 2xx (the body is not read into the message)
     */
    public function asyncGet(int $ms, int|string $id, ?Cancellation $cancellation = null, int $status = 200): string
    {
        $response = $this->client->request(new Request($this->url($ms, $id, $status)), $cancellation);
        $body = $response->getBody()->buffer($cancellation);
        if ($response->getStatus() < 200 || $response->getStatus() > 299) {
            throw new RuntimeException("GET item $id answered with HTTP status {$response->getStatus()}");
        }

        return $body;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
