# Getting Started

## 📦 Installation

Install via Composer:

```bash
composer require antevemus/aspecification-revolt
```

Requirements: PHP 8.2 or later. Installed with it: [`antevemus/aspecification`](https://github.com/antevemus-it/Antevemus.ASpecification) `^2.6` (the core, from the same author; 2.6.0 is the first release with the hook this package plugs into, `IDeferredDataSource` and `IStageTransport`), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` (the event loop) and [`amphp/amp`](https://github.com/amphp/amp) `^3.0` (futures, cancellations, `delay()`). No PHP extension is required (the core's `HttpDataSource` needs `ext-curl`; this package does not). The package uses the application's Revolt event loop when there is one and never creates or replaces a driver.

What it is for: the **non-HTTP** external data of a specification (`Spec::external()`) that a non-blocking client can fetch: an Amp or Revolt client, an asynchronous SDK, a driver that suspends a Fiber. The core already fetches its HTTP sources together (`curl_multi`) and runs a `CallableDataSource` synchronously, one after the other; a `RevoltDataSource` is fetched with the other Revolt sources of its stage, at the same time. A Fiber only gains time while the code inside it waits on non-blocking I/O; blocking calls (`file_get_contents()`, `curl_exec()`, PDO) and CPU work run one after the other. See [How it works](../concepts/how-it-works.md).

### Git VCS Repository (Development or Private Environments)

If using directly from GitHub prior to or alongside Packagist:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/antevemus-it/Antevemus.ASpecificationRevolt.git"
        }
    ],
    "require": {
        "antevemus/aspecification-revolt": "^1.0"
    }
}
```

## 🚀 Quick Start

<!-- tested: quick-start -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

// Three services that answer in 100 ms; Amp\delay() stands for an Amp HTTP client or an async SDK
$service = fn(mixed $answer): RevoltDataSource => new RevoltDataSource(
    function (array $p, DataRequest $request, Cancellation $cancellation) use ($answer): mixed {
        delay(0.1, cancellation: $cancellation); // 100 ms of non-blocking I/O
        return $answer;
    },
    timeout: 2.0,
);

$registry = (new DataSourceRegistry())
    ->register('bureau.score', $service(742))
    ->register('kyc.status', $service('verified'))
    ->register('sanctions.hit', $service(false));

$creditRule = Spec::external('bureau.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
    ->and(Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified')))
    ->and(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isFalse()));

$started = microtime(true);
$result = (new DataResolver($registry))->evaluate($creditRule, (object) ['cpf' => '111.444.777-35']);
$elapsed = microtime(true) - $started;

$result->isSatisfied; // true
$elapsed < 0.25; // true (the three waits overlapped: about 0.1 s, not 0.3 s)
```

The rule is an ordinary specification of the core: `Spec::external()` declares the data, the `DataResolver` collects the requests of the whole tree, hands the three Revolt requests of the stage to one `RevoltStageTransport::fetchAll()` (the shared transport, since none was given), which runs the three closures in three Fibers, and only then evaluates the tree. The closure, its time limit, its failures and the transport: [RevoltDataSource and the transport](../api/revolt-data-source.md).
