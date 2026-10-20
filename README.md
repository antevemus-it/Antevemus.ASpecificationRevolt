# Antevemus ASpecification Revolt

[![CI](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/badge/release-v1.0.0-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/releases)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Tests Passing](https://img.shields.io/badge/tests-85%20passed%20%7C%204974%20assertions-success.svg)](docs/en/quality/tests.md)
[![Core: ASpecification](https://img.shields.io/badge/Core-Antevemus.ASpecification-orange)](https://github.com/antevemus-it/Antevemus.ASpecification)

> **The Revolt/Fibers satellite of [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) for PHP 8.2+**  
> `RevoltDataSource` and `RevoltStageTransport` fetch the non-HTTP external data of a resolution stage concurrently under the Revolt event loop (Fibers, Amp futures), through the `IDeferredDataSource`/`IStageTransport` hook of the core: a concurrency limit, a time limit per source and the resolution deadline, with the same failures as the core's own sources. For data behind non-blocking clients (Amp, async SDKs).

---

[🇺🇸 English](README.md) • [🇧🇷 Português (Brasil)](README.pt-BR.md)

---

The documentation lives in [`docs/en`](docs/en/index.md) (English) and [`docs/pt-BR`](docs/pt-BR/index.md) (Portuguese, Brazil), one page per topic, in the Docs Kit format (a `docs-nav.json` per language), and every PHP block of the pages is run by the test suite.

---

## 🚀 Quick Start

The same block opens [Getting Started](docs/en/getting-started/index.md), and the test suite runs it with the answers written in its comments:

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

---

## 📦 Installation

Install via Composer: `composer require antevemus/aspecification-revolt`. Requirements: PHP 8.2 or later; installed with it: [`antevemus/aspecification`](https://github.com/antevemus-it/Antevemus.ASpecification) `^2.6` (the core, same author; 2.6.0 for the `IDeferredDataSource`/`IStageTransport` hook), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` and [`amphp/amp`](https://github.com/amphp/amp) `^3.0`. No PHP extension is required.

A Fiber only gains time while the code inside it waits on **non-blocking** I/O: blocking calls (`file_get_contents()`, `curl_exec()`, PDO) and CPU work run one after the other, whatever the concurrency. Measured against a local HTTP server that answers in 200 ms, one resolution of 1, 4 and 16 sources (median of 3 runs, PHP 8.4.13):

| Sources × 200 ms | CallableDataSource, blocking | RevoltDataSource (8), Amp client | RevoltDataSource (16), Amp client | HttpDataSource (curl_multi) | RevoltDataSource (16), blocking |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 203 ms | 202 ms | 203 ms | 201 ms | 202 ms |
| 4 | 809 ms | 208 ms | 211 ms | 205 ms | 809 ms |
| 16 | 3 227 ms | 428 ms | 215 ms | 210 ms | 3 244 ms |

A `RevoltDataSource` gains on the non-blocking client only (the Amp columns); with a blocking client it takes as long as the callables, and so does a closure that only computes. The core's own `HttpDataSource` batch is the reference for HTTP. How it was measured, the CPU-only case and the tests that assert it: [Tests](docs/en/quality/tests.md).

---

## 📚 Documentation

**Getting started**

- [Installation and quick start](docs/en/getting-started/index.md) · [pt-BR](docs/pt-BR/getting-started/index.md): Composer, the requirements and the Quick Start.

**Concepts**

- [How it works](docs/en/concepts/how-it-works.md) · [pt-BR](docs/pt-BR/concepts/how-it-works.md): The three groups of a resolution stage, the transport and the runner, the event loop, and why Fibers gain time only on non-blocking I/O.

**API reference**

- [RevoltDataSource and the transport](docs/en/api/revolt-data-source.md) · [pt-BR](docs/pt-BR/api/revolt-data-source.md): The constructor, the closure, cost and TTL, the time limit, the failures and their exceptions, `maxConcurrency`, the shared transport and the use inside a Fiber.

**Quality**

- [Tests](docs/en/quality/tests.md) · [pt-BR](docs/pt-BR/quality/tests.md): The PHPUnit report, the parity with the core's sources, the measured timing table and the mutation testing.

**Project**

- [Roadmap](docs/en/project/roadmap.md) · [pt-BR](docs/pt-BR/project/roadmap.md): The release shipped and what comes next; the milestone view is in ROADMAP.md.

---

## 🧪 Quality

- **Tests:** 85 PHPUnit tests (`vendor/bin/phpunit`) and 4974 assertions, 100% passing on PHP 8.4 (and on 8.2; the CI runs 8.2, 8.3 and 8.4): the source, the transport and the runner, the parity with the core's sources, properties over random trees, the proof of gain against a local HTTP server, and the code blocks of the documentation.
- **Parity:** the same trees give the same verdicts, evaluation errors, failed requests, exception classes and source names through `CallableDataSource`, `HttpDataSource` and `RevoltDataSource` (150 comparisons with the callable source under the three policies, 52 against the local server).
- **Properties:** 300 seeded random trees over Revolt sources on 1-3 transports mixed with callable sources: the same verdicts as through callable sources, under every policy; each request fetched once; each transport called at most once per stage.
- **Mutation testing:** Infection over the whole `src/`, MSI 96.69% (4 escaped mutants, all equivalent); the Quality workflow requires 95%.
- **The report**, the timing table and the mutation run: [Tests](docs/en/quality/tests.md).

---

## 🗺️ Roadmap & Ecosystem

The release shipped and what comes next: [Roadmap](docs/en/project/roadmap.md). The milestone view: 👉 **[ROADMAP.md](ROADMAP.md)**. The core and its own roadmap: [Antevemus.ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification). The asynchronous companion of ALinq, whose runner this package copies: [Antevemus.AlinqRevolt](https://github.com/antevemus-it/Antevemus.AlinqRevolt).

---

## 📄 License & Authors

This software is open-source, licensed under the **[MIT License](LICENSE)**.

- **Author:** Heliton Junior (CTO) — [`contato@antevemus.com.br`](mailto:contato@antevemus.com.br)
- **Organization:** [Antevemus Soluções Inovadoras em TI Ltda.](https://antevemus.com.br)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
