# Antevemus ASpecification Revolt

The official documentation of the package, one page per topic. The same pages exist in Portuguese (Brazil), in `docs/pt-BR`, and every PHP block of these pages is run by the test suite with the answers written in its comments.

> **The Revolt/Fibers satellite of [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) for PHP 8.2+**  
> `RevoltDataSource` and `RevoltStageTransport` fetch the non-HTTP external data of a resolution stage concurrently under the Revolt event loop (Fibers, Amp futures), through the `IDeferredDataSource`/`IStageTransport` hook of the core: a concurrency limit, a time limit per source and the resolution deadline, with the same failures as the core's own sources. For data behind non-blocking clients (Amp, async SDKs).

## Documentation Map

### Getting started

- [Installation and quick start](getting-started/index.md): Composer, the requirements and the Quick Start.

### Concepts

- [How it works](concepts/how-it-works.md): The three groups of a resolution stage, the transport and the runner, the event loop, and why Fibers gain time only on non-blocking I/O.

### API reference

- [RevoltDataSource and the transport](api/revolt-data-source.md): The constructor, the closure, cost and TTL, the time limit, the failures and their exceptions, `maxConcurrency`, the shared transport and the use inside a Fiber.

### Quality

- [Tests](quality/tests.md): The PHPUnit report, the parity with the core's sources, the measured timing table and the mutation testing.

### Project

- [Roadmap](project/roadmap.md): The release shipped and what comes next; the milestone view is in ROADMAP.md.

## Where Is Each Thing

| What | Where |
| :--- | :--- |
| Overview, badges and the Quick Start | [README.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/README.md) |
| What changed in each version | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/CHANGELOG.md) |
| Milestones shipped and planned | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/ROADMAP.md) |
| Provenance and third-party licenses | [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/THIRD_PARTY_NOTICES.md) |
| License | [LICENSE](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/LICENSE) |
| The core and its resolution of external data | [Antevemus.ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) |
