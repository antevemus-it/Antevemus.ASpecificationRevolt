# How It Works

## The hook of the core: three groups per stage

The `DataResolver` of [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) fetches the external data of a whole specification tree before evaluating it, stage by stage (one stage under `concurrent()`, one per cost under `tiered()`, one per leaf under `sequential()`). Since 2.6.0 a stage fetches its pending requests in three groups, always in this order ([Deferred sources and stage transports](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/docs/en/resolution/deferred.md) in the core's documentation):

1. **the HTTP sources** (`HttpDataSource`), together, in one `curl_multi` batch;
2. **the deferred sources** (`IDeferredDataSource`), grouped by the transport they name (`getTransport()`): one `IStageTransport::fetchAll()` call per transport, with every pending request of that transport in the stage;
3. **the other sources** (`CallableDataSource` and any other `IDataSource`), one after the other.

The groups are **serialized by the core**: the transports start after the HTTP batch has ended, and each one ends before the next starts. The concurrency is inside each group. A `RevoltDataSource` is a deferred source, and a `RevoltStageTransport` is its transport.

## The transport and the runner

`RevoltStageTransport::fetchAll()` receives the Revolt requests of the stage, by key, and:

1. starts one Fiber per request, through its `RevoltRunner` (`Amp\async()`), at most `maxConcurrency` at a time (8 by default); the others wait for a free slot, in key order;
2. runs, in each Fiber, the closure of the source with the request's parameters, the `DataRequest` and an `Amp\Cancellation`; the closure may return a value or an `Amp\Future`, or suspend;
3. limits each request with the `timeout` of its source, counted from the request's start, and every request of the call with the resolution deadline (`ResolutionPolicy::concurrent(timeout: ...)`), one `Amp\TimeoutCancellation` shared by all of them;
4. waits for all of them and answers by key: the value, or the exception that failed the request.

The runner is the `RevoltRunner` of `antevemus/alinq-revolt` (the asynchronous companion of ALinq), copied here on purpose so neither package depends on the other. One transport caps every call through it: two resolutions running at the same time in two Fibers share its slots. `RevoltStageTransport::shared()` is the transport of every `RevoltDataSource` built without one.

<!-- tested: stage -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

$finished = [];
$registry = (new DataSourceRegistry())
    ->register('ledger.balance', new RevoltDataSource(function (array $p) use (&$finished): float {
        delay(0.06); // 60 ms of non-blocking I/O
        $finished[] = 'ledger.balance';
        return 2500.0;
    }))
    ->register('kyc.status', new RevoltDataSource(function (array $p) use (&$finished): string {
        delay(0.04); // 40 ms
        $finished[] = 'kyc.status';
        return 'verified';
    }))
    ->register('risk.local', new CallableDataSource(function (array $p) use (&$finished): int {
        $finished[] = 'risk.local';
        return 3;
    }));

$rule = Spec::external('risk.local', ['account' => 'account'], Spec::lessThan(5))
    ->and(Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000)))
    ->and(Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified')));

$started = microtime(true);
$data = (new DataResolver($registry))->resolve($rule, (object) ['cpf' => '111.444.777-35', 'account' => 'A-1']);
$elapsed = microtime(true) - $started;

$finished; // ['kyc.status', 'ledger.balance', 'risk.local']
$data->getStageCount(); // 1
$elapsed < 0.09; // true (the two Revolt requests overlapped: about 0.06 s, not 0.1 s)
$data->evaluate($rule, (object) ['cpf' => '111.444.777-35', 'account' => 'A-1'])->isSatisfied; // true
```

The callable source comes first in the tree, yet it runs last: the transport's group comes before the other sources. Inside the group the two Revolt requests started together, and the shorter one finished first.

## The event loop

The transport uses the application's Revolt event loop (`Revolt\EventLoop`) and never creates or replaces a driver. Waiting means `Amp\Future::await()`:

- **outside a Fiber** (a PHP-FPM request, a CLI script, a job), it runs the event loop until the requests settle, and `DataResolver::evaluate()` returns as it always does;
- **inside a Fiber** (an Amp HTTP server, a worker built on Revolt, `Amp\async()`), it suspends that Fiber only, and the rest of the application keeps running while the requests wait. [RevoltDataSource and the transport](../api/revolt-data-source.md#inside-a-fiber) shows it.

## Fibers gain time only on non-blocking I/O

A Fiber is a cooperative coroutine on the one PHP thread: it gives the thread back only when it waits on something the event loop watches (a socket of an Amp client, a timer of `Amp\delay()`). Overlapping those waits is the whole gain. A blocking call (`file_get_contents()`, `curl_exec()`, `usleep()`, PDO) holds the thread until it returns, and CPU work never gives it back, so inside a `RevoltDataSource` they run one after the other, whatever `maxConcurrency` is:

<!-- tested: blocking -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

// Four sources that wait 50 ms each, in one stage
$fourSources = function (\Closure $wait): float {
    $registry = new DataSourceRegistry();
    $rule = Spec::alwaysTrue();
    foreach (['s1', 's2', 's3', 's4'] as $name) {
        $registry->register($name, new RevoltDataSource(function (array $p) use ($wait): bool {
            $wait();
            return true;
        }));
        $rule = $rule->and(Spec::external($name, [], Spec::isTrue()));
    }
    $started = microtime(true);
    (new DataResolver($registry))->evaluate($rule, (object) []);
    return microtime(true) - $started;
};

$nonBlocking = $fourSources(fn() => delay(0.05)); // about 0.05 s: the four waits overlapped
$blocking = $fourSources(fn() => usleep(50_000)); // about 0.2 s: usleep() holds the thread, one call after the other

$nonBlocking < 0.15; // true
$blocking > 0.19; // true
```

The same holds over the network: against a local HTTP server that answers in 200 ms, 16 sources take about 3 200 ms as `CallableDataSource` with a blocking client, about 3 200 ms as `RevoltDataSource` with the same blocking client, about 200 ms as `RevoltDataSource` with the Amp HTTP client and `maxConcurrency` 16, and about 200 ms through the core's own `HttpDataSource` batch. A closure that only computes gains nothing either. The measured table and the tests that assert it: [Tests](../quality/tests.md).

So, for HTTP, the core's `HttpDataSource` is already concurrent without any dependency (and works under PHP-FPM with no event loop); `RevoltDataSource` is for the rest: SDKs and clients that are non-blocking, and HTTP calls that need what an Amp client offers. For CPU-bound work, Fibers are the wrong tool: it needs worker processes.

## The classes

| Class | Role |
| :--- | :--- |
| `RevoltDataSource` (final) | An `IDeferredDataSource`: the closure, `cost`, `ttl`, `timeout` and the transport; `fetch()` is the synchronous path outside a resolution scope. |
| `RevoltStageTransport` (final) | An `IStageTransport`: `fetchAll()` runs the requests of a stage together under the loop, with `maxConcurrency`, the per-source time limit and the resolution deadline; `shared()` is the process-wide default. |
| `RevoltRunner` (final) | Runs closures under the Revolt loop with a concurrency limit and a per-task time limit; a copy of the runner of `antevemus/alinq-revolt`. |
