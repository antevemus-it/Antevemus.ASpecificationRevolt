# RevoltDataSource and the Transport

```text
new RevoltDataSource(
    Closure $fetch,                          // (array $parameters, DataRequest $request, Amp\Cancellation $cancellation): mixed
    int $cost = 0,                           // relative cost: the tiered policy fetches cheaper sources first
    ?int $ttl = null,                        // seconds a value may be cached between resolutions (null or 0: never)
    float $timeout = 5.0,                    // time limit of one request, in seconds, from its start
    ?RevoltStageTransport $transport = null, // null: RevoltStageTransport::shared()
)

new RevoltStageTransport(int $maxConcurrency = 8)
RevoltStageTransport::shared(): RevoltStageTransport
```

`RevoltDataSource` implements the core's `IDeferredDataSource` (and so `IDataSource`): it is registered by name in a `DataSourceRegistry` and named by `Spec::external()` leaves, like any source. A negative `ttl`, and a `timeout` that is not a positive finite number of seconds, are refused when the source is built (`InvalidArgumentException`); a `maxConcurrency` below 1 when the transport is built. `getCost()`, `getTtl()`, `getTimeout()` and `getTransport()` read them back; `getMaxConcurrency()` the limit of a transport.

Cost, TTL, cache and deduplication behave as for any source of the core: two leaves asking the same data cause one request; a value is cached with the TTL of its source (`new DataResolver($registry, new InMemoryDataCache())`) and a request answered by the cache never reaches the closure; a failure is never cached; under `tiered()` each cost is a stage of its own, cheapest first, and a decided verdict skips the expensive stages.

## The closure

The closure receives the parameters of the request (sorted by name), as the callable of a `CallableDataSource` does, so `fn(array $p)` works; with a second parameter, the `DataRequest` (its `source` is the name the source was registered under); with a third (or when it is variadic), the `Amp\Cancellation` of the request, requested when its time limit or the resolution deadline runs out: pass it to the Amp client, to `Amp\delay()`, to the SDK. It runs in a Fiber under the event loop: it may suspend, and it may return a value or an `Amp\Future` (awaited with the same cancellation).

<!-- tested: closure -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\Future;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\async;
use function Amp\delay;

$byParameters = new RevoltDataSource(fn(array $p): string => "account {$p['account']}");
$withTheRequest = new RevoltDataSource(fn(array $p, DataRequest $request): string => $request->source);
$withTheCancellation = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): bool {
    delay(0.01, cancellation: $cancellation); // the Amp client receives it the same way
    return $cancellation->isRequested();
});
$returningAFuture = new RevoltDataSource(fn(array $p): Future => async(fn(): int => 42));

// fetch(): the synchronous path, outside a resolution (here, to show what each closure answers)
$request = new DataRequest('ledger.balance', ['account' => 'A-1']);
$byParameters->fetch($request); // 'account A-1'
$withTheRequest->fetch($request); // 'ledger.balance'
$withTheCancellation->fetch($request); // false
$returningAFuture->fetch($request); // 42
```

Inside a resolution the resolver never calls `fetch()`: it hands every pending request of the stage that shares a transport to one `fetchAll()`. `fetch()` is the path of a leaf evaluated outside a resolver (`$spec->evaluate($candidate)`, through the default registry) and of the `sequential()` policy's single requests: one request through the source's transport, its value returned or its failure thrown. Outside a Fiber it runs the event loop; inside one it suspends that Fiber.

## Failures

A failure of one request is the evaluation error of the leaf that needed it, as for every source of the core: under `evaluate()` the leaf is unsatisfied with `isError`, the generic message, `metadata['evaluation_error'] = true`, the exception in `$result->exception` for the log; `getFailedRequests()` lists the request; the other requests of the stage are not affected. The exceptions are the core's, with the same factories as its HTTP and callable sources, so the same tree gives the same verdicts and the same failures through a `CallableDataSource`, an `HttpDataSource` and a `RevoltDataSource` (the parity tests of [Tests](../quality/tests.md)):

| What failed | The exception | As the core does for |
| :--- | :--- | :--- |
| the closure threw (or its `Future` failed) | `DataFetchException::sourceFailed()`: `Data source "<name>" failed (<class>).`, the original as `getPrevious()` | a `CallableDataSource` that throws |
| the closure threw a `DataPendingException` | that exception, as it is: the request is listed by `getPendingRequests()` and `$result->isPending()` is true | an `AsyncDataSource` whose result is not ready |
| the source's own `timeout` ran out | `DataFetchException::timeout()`: `Data source "<name>" did not answer within its time limit of <s> s.` | an `HttpDataSource` past its own time limit |
| the resolution deadline ran out | `DataFetchException::resolutionTimeout()`: `The time limit of the resolution was reached before data source "<name>" answered.` | any source when `ResolutionPolicy::...(timeout: ...)` runs out |

The messages carry the name of the source and the class of the original exception only, never a parameter value nor the original message (which stays in `getPrevious()`, for the log).

<!-- tested: failures -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

$offline = new RevoltDataSource(fn(array $p): float => throw new \RuntimeException("ledger offline for {$p['account']}"));
$slow = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): float {
    delay(1.0, cancellation: $cancellation);
    return 2500.0;
}, timeout: 0.05);
$notReady = new RevoltDataSource(fn(array $p): string => throw new DataPendingException('bureau.analysis', 'T-1'));

$request = new DataRequest('ledger.balance', ['account' => 'A-1']);
$offline->fetch($request); // throws DataFetchException: Data source "ledger.balance" failed (RuntimeException).
$slow->fetch($request); // throws DataFetchException: Data source "ledger.balance" did not answer within its time limit of 0.050 s.
$notReady->fetch(new DataRequest('bureau.analysis', [])); // throws DataPendingException: Data source "bureau.analysis" accepted the request; its result is not ready yet.

// The resolution deadline: the requests still running are cancelled at it
$patient = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): float {
    delay(1.0, cancellation: $cancellation);
    return 2500.0;
}, timeout: 5.0);
$registry = (new DataSourceRegistry())->register('ledger.balance', $patient);
$rule = Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000));
$result = (new DataResolver($registry))->evaluate($rule, (object) ['account' => 'A-1'], ResolutionPolicy::concurrent(timeout: 0.05));

$result->isError; // true
$result->exception->getMessage(); // 'The time limit of the resolution was reached before data source "ledger.balance" answered.'
$result->failures[0]->metadata['evaluation_error']; // true
```

The deadline is the instant the core passes to `fetchAll()`: one `Amp\TimeoutCancellation` of the time left is shared by every request of the call, so the unfinished ones are cancelled at it and `fetchAll()` returns then, the requests still waiting for a slot included (their closures never run). With less time left than the core's minimum budget (`CurlMultiTransport::MIN_START_BUDGET`, 10 ms), nothing is started and every request fails with `resolutionTimeout()`, as the core's `curl_multi` batch does. A value that arrived before the deadline is kept. A closure that ignores its cancellation keeps running in the background until it returns, holding its slot, but its request has already failed.

## The transport and `maxConcurrency`

A `RevoltStageTransport` runs at most `maxConcurrency` requests at the same time; the others wait for a free slot, in key order, and start as slots free up, so N requests of the same duration d take about ⌈N / maxConcurrency⌉ × d. The limit counts the closures really running, across every `fetchAll()` through the transport, including two resolutions running at the same time in two Fibers. A source built without a transport uses `RevoltStageTransport::shared()`, one process-wide instance with a limit of 8, created on first use. Give a source its own transport when it needs another limit (a partner API that accepts four calls at a time) or when its requests must not share slots with the others; every source on the same transport is fetched by the same `fetchAll()` call of a stage.

<!-- tested: transport -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;

use function Amp\delay;

$running = 0;
$peak = 0;
$partnerApi = function (array $p) use (&$running, &$peak): bool {
    $running++;
    $peak = max($peak, $running);
    delay(0.05); // 50 ms per call
    $running--;
    return true;
};

// Twelve partner checks through a transport that allows four calls at a time
$fourAtATime = new RevoltStageTransport(maxConcurrency: 4);
$registry = new DataSourceRegistry();
$rule = Spec::alwaysTrue();
foreach (range(1, 12) as $i) {
    $registry->register("partner.$i", new RevoltDataSource($partnerApi, transport: $fourAtATime));
    $rule = $rule->and(Spec::external("partner.$i", [], Spec::isTrue()));
}

$started = microtime(true);
$data = (new DataResolver($registry))->resolve($rule, (object) []);
$elapsed = microtime(true) - $started;

$peak; // 4
$data->getStageCount(); // 1
$elapsed > 0.14; // true (three rounds of four calls of 0.05 s)
(new RevoltDataSource(fn(array $p): bool => true))->getTransport() === RevoltStageTransport::shared(); // true
RevoltStageTransport::shared()->getMaxConcurrency(); // 8
```

A source that implements `IDeferredDataSource` some other way and names a `RevoltStageTransport` is fetched by it too, through its own `fetch()` inside a Fiber: without a time limit of its own, its exception kept as it is (as the core keeps the exception of a source fetched one after the other), the resolution deadline applied.

## Inside a Fiber

Under an Amp HTTP server, a worker built on Revolt or any `Amp\async()`, the resolution suspends only the Fiber that runs it: the event loop keeps serving the rest of the application while the requests wait.

<!-- tested: fiber -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

$registry = (new DataSourceRegistry())->register('kyc.status', new RevoltDataSource(function (array $p): string {
    delay(0.05); // 50 ms of non-blocking I/O
    return 'verified';
}));
$rule = Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified'));
$resolver = new DataResolver($registry);

$events = [];
[$approved] = await([
    async(function () use ($resolver, $rule, &$events): bool {
        $verdict = $resolver->evaluate($rule, (object) ['cpf' => '111.444.777-35'])->isSatisfied;
        $events[] = 'resolved';
        return $verdict;
    }),
    async(function () use (&$events): void {
        delay(0.01);
        $events[] = 'another request served';
    }),
]);

$approved; // true
$events; // ['another request served', 'resolved']
```
