# Antevemus ASpecification Revolt — Test Suite

PHPUnit 11 test suite of **Antevemus ASpecification Revolt**, the Revolt/Fibers satellite of Antevemus ASpecification.

## 📊 Overview & Metrics

- **Test Framework:** PHPUnit 11.5+ (PHP 8.2+), `phpunit.xml.dist` with `failOnWarning`, `failOnDeprecation` and `failOnRisky`
- **Test Files:** 9 test classes in `tests/Unit` (6), `tests/Property` (1) and `tests/Http` (2), plus the fixtures of `tests/Support`
- **Total Tests:** 85 tests (1.0.1)
- **Total Assertions:** 5042 assertions, none skipped (PHP 8.4.13; the same suite passes on PHP 8.2.34)
- **Success Rate:** 100% Passing (0 failures, 0 errors, 0 deprecations)
- **Runtime Dependencies:** `antevemus/aspecification` ^2.6, `revolt/event-loop` ^1.0, `amphp/amp` ^3.0; the tests also use `amphp/http-server` and `amphp/http-client` (require-dev) and the core's `HttpDataSource` (`ext-curl`) as the reference of the HTTP tests

---

## 🏛️ Test Suite Structure

### 1. `tests/Unit/RevoltStageTransportTest.php` (20 tests, 136 assertions)
`RevoltStageTransport`:
- `maxConcurrency` below 1 refused; the default 8; `shared()` one instance, the transport of a source built without one; nothing pending answers nothing
- Answers by key, in the order received; the closure receives the `DataRequest` and a `Cancellation`; a returned `Future`
- An exception of the closure as `sourceFailed()` (`Data source "bureau" failed (RuntimeException).`, the original as previous, no parameter value), failing its request only
- The source's own time limit as `timeout()` (`... did not answer within its time limit of 0.050 s.`), the closure cancelled; a closure that ignores its cancellation still fails at its limit and returns in the background
- The resolution deadline: 16 closures of 1 s, a deadline of 50 ms, limit 8: every request fails with `resolutionTimeout()`, the call returns between 40 and 300 ms, 8 closures started and 8 cancelled, none completed, nothing running and no enabled referenced watcher left in the event loop; the same through `DataResolver` with `ResolutionPolicy::concurrent(timeout: 0.05)` and limit 16
- A deadline below the core's `MIN_START_BUDGET` (or already past) starts no closure; a budget above it does
- The deadline and the source's limit told apart; a value ready before the deadline kept when its key is awaited after it
- `maxConcurrency` 1, 3, 8, 16 over 16 sources: the peak of closures running at once equals the limit, and the time follows the rounds (8: between 180 and 600 ms for 16 × 100 ms)
- Requests waiting for a slot (limit 1, a closure ignoring its cancellation) fail at the deadline and never run their closure
- `DataPendingException` passed through as it is (`getPendingRequests()`, `isPending()`); an exception with a pending one inside wrapped, still recognisable
- Another `IDeferredDataSource` fetched through its own `fetch()` (its value, its exception as it is, the deadline)
- Outside a Fiber the wait runs the loop (a repeating timer ticks during it); inside a Fiber two `DataResolver::evaluate()` calls in `Amp\async()` share the transport (peak 4) while a third Fiber runs first
- Two concurrent `fetchAll()` capped together by one transport; a stage of Revolt and callable sources in the core's order

### 2. `tests/Unit/RevoltDataSourceTest.php` (13 tests, 46 assertions)
`RevoltDataSource`: a negative TTL and a zero, negative, infinite or NaN timeout refused; the getters and the shared transport; the arity of the closure (`fn(array $p)`, two parameters receiving two arguments, three receiving the `Cancellation`, variadic receiving three); a returned `Future` awaited, its failure and its time limit; `fetch()` throwing `sourceFailed()`, `timeout()` and a `DataPendingException` as it is; `fetch()` through the source's own transport (limit 1: one after the other); a leaf outside a resolution scope through the default registry; `fetch()` inside a Fiber suspending only it; the sequential policy (each leaf when reached, short-circuited by `isSatisfiedBy()`) and the cache (a TTL answered by the cache).

### 3. `tests/Unit/RevoltRunnerTest.php` (16 tests, 50 assertions)
`RevoltRunner`, copied with the class from `antevemus/alinq-revolt` 1.0.0 (only the namespaces changed): the concurrency and timeout checks, a task's value, a returned `Future`, its exception, the slot limit with the callers in arrival order, the time limit, a task ignoring its cancellation keeping its slot, the cancellation passed in, `cancel()`.

### 4. `tests/Unit/ParityTest.php` (2 tests, 161 assertions)
The parity of answers with `CallableDataSource`: 10 trees, 5 candidates (values, a failing source, a source past its time limit, a null datum, an unknown source, a candidate without the parameter and one with a null parameter), 3 policies: 150 comparisons of everything `tests/Support/ResolutionSummary.php` gathers (verdict, error and pending flags, `evaluation_error` flags, reasons, codes, exception class and source name, failed/known/skipped requests, stage and fetch counts, class and source name of each failed request); and the battery itself (each kind of answer happens).

### 5. `tests/Property/ResolutionPropertyTest.php` (1 test, 3935 assertions)
300 seeded cases (seed 45045): random trees over 3-6 sources (Revolt on 1-3 transports with random limits, or callable; one answer in six failing; random costs), the same verdict as through callable sources under `concurrent()`, `tiered()` and `sequential()` and across them; `getFetchCount()` equal to the distinct requests fetched; each transport's `fetchAll()` once under `concurrent()` and at most once per stage under `tiered()`, counted by `tests/Support/CountingStageTransport.php`. Every check counts as an assertion; the generator reaches 63 two-transport stages, 59 tiered skips, 174 evaluation errors and 44 transports called in two tiered stages.

### 6. `tests/Http/HttpParityTest.php` (2 tests, 61 assertions, group `http`)
Against the local Amp server: 8 trees, 2 candidates (one whose parameter holds `/`, `?` and `..`), `concurrent()` and `sequential()`, through `HttpDataSource`, `RevoltDataSource` with the Amp client and (for the 5 trees without a time limit) `CallableDataSource` with a blocking client: 52 comparisons. The failures of each side: `httpStatus()` with status 503 for HTTP, `sourceFailed()` for the closures, the same `timeout()` message for HTTP and Revolt.

### 7. `tests/Http/ProofOfGainTest.php` (6 tests, 19 assertions, group `http`)
One resolution of N leaves of `GET /item?ms=200`: callables with a blocking client above 700 ms (4) and 2 500 ms (16); Revolt with the Amp client below 600 ms (16 at limit 16), 800 ms (16 at 8), 500 ms (4) and 450 ms (1), never below the rounds; `HttpDataSource` below 600 ms (16); Revolt 16 at least three times faster than the callables; a blocking client inside Revolt above 2 500 ms; 8 CPU-only closures at least 70% of the sequential time. Failure messages carry the measured times; the tests are skipped with the reason if the server cannot start. About 11 s; exclude them with `--exclude-group http`.

### 8. `tests/Unit/DocumentationBlocksTest.php` (17 tests, 127 assertions)
Runs the 7 PHP blocks of `docs/en` and `docs/pt-BR`, marked `<!-- tested: id -->`, and the Quick Start of each README, as written, and checks every answer in their comments; both languages carry the same blocks with the same code (comments aside) and the same answers.

### 9. `tests/Unit/DocumentationTreeTest.php` (8 tests, 507 assertions)
The guard of the documentation, adapted from `antevemus/alinq-revolt`: the same files and navigation in both languages, every link resolving, the same code token by token, the declared PHP blocks (7 per language), each README an index whose only code is the Quick Start, every PHP block tested, nothing internal leaking.

### Support
`ClosureProbe` (counts started, running, peak, completed and cancelled closures), `CountingStageTransport` (a decorating transport), `ResolutionSummary` (the comparable account of a resolution), `StageBench` (the bench of the proof of gain and of the table), `LatencyServer` and `latency-server.php` (the Amp server, with a `status` parameter), `timing-table.php`.

---

## 🚀 Running the Tests

```bash
# The whole suite (Xdebug off: a debugger session would make the suite wait)
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage

# Without the HTTP tests (no local server, about 11 s less)
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage --exclude-group http

# The timing table of the documentation
XDEBUG_MODE=off php tests/Support/timing-table.php 3

# Mutation testing
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

---

## 📄 License & Copyright

- **License:** MIT License — see [LICENSE](../LICENSE)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
