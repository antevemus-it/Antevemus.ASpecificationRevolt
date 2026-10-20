# 🧪 Tests

The suite runs on PHPUnit 11 under PHP 8.2, 8.3 and 8.4 (the CI matrix), with no PHP extension required by the package (the core's `HttpDataSource`, the reference of the HTTP tests, uses `ext-curl`):

```bash
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
```

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.13
Configuration: phpunit.xml.dist

................................................................. 65 / 85 ( 76%)
....................                                              85 / 85 (100%)

Time: 00:20.813, Memory: 8.00 MB

OK (85 tests, 4974 assertions)
```

- **85 tests and 4974 assertions**, none skipped, in three suites: `tests/Unit` (the two classes, the runner, the parity with the callable source, the documentation), `tests/Property` (random trees) and `tests/Http` (the HTTP parity and the proof of gain, group `http`). The same suite passed under PHP 8.2.34 (a container, with the dependencies resolved for 8.2). The file-by-file account is in `tests/README.md`.
- **The transport** (`RevoltStageTransportTest`): answers by key, in the order received; an exception of the closure as `sourceFailed()` that fails its request only, with the source's name and never a parameter value; the source's own time limit as `timeout()`, the closure cancelled through its `Cancellation`, and a closure that ignores it still failing at its limit; the resolution deadline (16 closures of 1 s, a deadline of 50 ms: every request fails with `resolutionTimeout()`, the call returns in about 50 ms, the eight closures that started are cancelled, the eight that waited for a slot never run, and no timer is left in the event loop); a deadline below the core's minimum budget starting nothing; the deadline and the source's limit told apart; a value ready before the deadline kept whatever the order of the keys; `maxConcurrency` honoured (the peak of closures running at once is 1, 3, 8 and 16 for those limits; 16 sources of 100 ms take about 200 ms at 8); requests waiting for a slot not outlasting the deadline; a `DataPendingException` passed through as it is (`getPendingRequests()`, `isPending()`); another deferred source fetched through its own `fetch()`; outside a Fiber the wait runs the loop, inside one (`DataResolver::evaluate()` in `Amp\async()`) it suspends only that Fiber while another one runs; two resolutions sharing a transport capped together; a stage mixing Revolt and callable sources in the core's order.
- **The source** (`RevoltDataSourceTest`): the argument checks, the getters and the shared transport, the arguments of the closure by its arity (one, two, three parameters, variadic), a returned `Future` awaited (its failure and its time limit included), `fetch()` throwing the mapped failures, `fetch()` through the source's own transport, a leaf outside a resolution scope taking `fetch()`, `fetch()` inside a Fiber, the sequential policy and the cache.
- **The runner** (`RevoltRunnerTest`, copied with the class from `antevemus/alinq-revolt`): the value of a task, its exception, the slot limit with the waiting callers in arrival order, the time limit, the cancellation passed in, `cancel()`.
- **Parity with the core's sources** (`ParityTest`, `HttpParityTest`): the same 10 trees over the same battery (values, a failure, a time limit, a missing datum, an unknown source, a candidate without the parameter or with a null one), 5 candidates, under `concurrent()`, `tiered()` and `sequential()`, through `CallableDataSource` and through `RevoltDataSource`: the same verdict, evaluation error and pending flags, `evaluation_error` flags, reasons, codes, exception class and source name, failed, known and skipped requests, stage and fetch counts (150 comparisons). Against the local Amp server, 8 trees through `HttpDataSource`, `RevoltDataSource` with the Amp client and `CallableDataSource` with a blocking client: an answer, a status 503 (a failure in all three) and the source's time limit (HTTP and Revolt give the same message), under `concurrent()` and `sequential()` (52 comparisons).
- **Properties** (`ResolutionPropertyTest`, 300 seeded cases, fixed seed): random trees of `Spec::external()` leaves over 3-6 random sources (one in six failing), each a `RevoltDataSource` on one of 1-3 transports or a `CallableDataSource`, with random costs: the same verdict through the Revolt sources and through callable sources with the same answers, under the three policies and across them; `getFetchCount()` equal to the number of distinct requests fetched; each transport's `fetchAll()` called once under `concurrent()` when it has requests, and under `tiered()` at most once per stage (one cost per call, never two calls for the same cost, no more calls than stages). Counted through a decorating `IStageTransport` (`RevoltStageTransport` is final). Measured with the seed: 63 cases with two transports or more in a stage, 59 tiered resolutions that skipped requests, 174 evaluation errors, 44 transports called in two tiered stages.
- **The documentation** (`DocumentationBlocksTest`, `DocumentationTreeTest`): the 7 PHP blocks of each language and the Quick Start of each README run as written, with every answer in their comments checked; both languages have the same pages, navigation and code, token by token; every link resolves; nothing internal leaks.

## Proof of gain

`tests/Http/ProofOfGainTest.php` starts a non-blocking Amp HTTP server on a free port of 127.0.0.1 in a child process (`tests/Support/latency-server.php`, adapted from `antevemus/alinq-revolt`; `GET /item?ms=200` answers after 200 ms without blocking) and runs one resolution of N external data leaves, each a distinct request, in one stage of the `DataResolver`. It asserts, with generous bounds and the measured times in every failure message: `CallableDataSource` with a blocking client above 700 ms for 4 sources and 2 500 ms for 16; `RevoltDataSource` with the Amp HTTP client below 600 ms for 16 sources at `maxConcurrency` 16, below 800 ms at the default 8, below 500 ms for 4 and below 450 ms for 1, never below the rounds it needs; the core's `HttpDataSource` below 600 ms for 16; Revolt at 16 at least three times faster than the callables; the same blocking client inside a `RevoltDataSource` above 2 500 ms; and 8 closures that only compute (about 25 ms each, calibrated) taking at least 70% of the sequential time as `RevoltDataSource`. When the server cannot start, the tests are skipped with the reason, never passed.

The table, measured with `XDEBUG_MODE=off php tests/Support/timing-table.php 3` (median of 3 runs per cell, PHP 8.4.13, a new Amp client per run):

| Sources × 200 ms | CallableDataSource, blocking | RevoltDataSource (8), Amp client | RevoltDataSource (16), Amp client | HttpDataSource (curl_multi) | RevoltDataSource (16), blocking |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 203 ms | 202 ms | 203 ms | 201 ms | 202 ms |
| 4 | 809 ms | 208 ms | 211 ms | 205 ms | 809 ms |
| 16 | 3 227 ms | 428 ms | 215 ms | 210 ms | 3 244 ms |

| 8 closures of about 25 ms of CPU | CallableDataSource | RevoltDataSource (16) |
| ---: | ---: | ---: |
| 8 | 278 ms | 268 ms |

- **Why each number:** a blocking call holds the only thread, so n sources take n × 200 ms whatever the transport (the first and last columns). With the Amp client, `maxConcurrency` requests wait together: 16 sources take ⌈16 / limit⌉ rounds of 200 ms (428 ms at 8 for two rounds, 215 ms at 16 for one); 4 sources fit in one round at 8 and 16. The core's `curl_multi` batch overlaps the same calls without any event loop (210 ms). The few milliseconds above each multiple of 200 are the requests themselves over loopback and the resolution around them.
- **CPU-only closures:** a Fiber that computes never gives the thread back, so 8 closures of about 25 ms take about 8 × 25 ms either way. This row moves from run to run on the machine of the measurement (WSL2, a shared CPU): two complete runs of the same command gave 254 and 398 ms, then 278 and 268 ms; the table shows the second. In none of them is the Revolt column meaningfully below the sequential one, which is the point.

## Mutation testing

Infection over the whole `src/` (`infection.json5`, the Quality workflow), the group `http` left out because it measures wall-clock time:

```bash
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

Measured on 2026-10-20 (Infection 0.35.8, PHP 8.4.13 with pcov): **121 mutants; 115 killed by the tests, 2 errors (detected), 4 escaped, all equivalent; MSI 96.69%** (covered-code MSI 96.69%, mutation code coverage 100%). By class: `RevoltDataSource` 36 of 36 killed; `RevoltStageTransport` 37 of 38; `RevoltRunner` 42 killed, 2 errors and 3 escaped of 47. The errors are two mutants of the runner that break the hand-over of a slot (the removed `suspend()` exhausts the memory; the inverted test of the waiting list calls `resume()` on nothing). A first run had one more escaped mutant: the `ignore()` of each request's future, redundant because every future is awaited afterwards (awaiting marks it handled), so the call was removed. The 4 escaped mutants:

- in `RevoltRunner` (the same three as in `antevemus/alinq-revolt`): removing `$everything->cancel()` in `cancel()`, whose `Amp\DeferredCancellation` is released right after and cancels in its destructor; removing the `unsubscribe()` of the combined cancellation when a task ends, a cancellation released with the task; turning the `finally` of a task into plain code after its `try`/`catch`, whose `catch (Throwable)` already receives every exception;
- in `RevoltStageTransport`: `<` turned into `<=` in the comparison of the time left with the core's minimum budget, two values of a monotonic clock that are never equal in practice (the core's own check is the same `<`).

`minMsi` and `minCoveredMsi` are 95 in `infection.json5`, one integer point below the measured score.
