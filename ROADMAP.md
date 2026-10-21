# Product Roadmap & Future Milestones — Antevemus ASpecification Revolt

This document outlines the engineering roadmap of **Antevemus ASpecification Revolt**, the Revolt/Fibers satellite of [Antevemus ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification): what shipped and what is only planned or deliberately left out. The list in [Roadmap](docs/en/project/roadmap.md) is the short form of this page.

---

## ✅ Completed Milestones

### 1. Non-HTTP Sources Fetched Concurrently under the Revolt Event Loop ⚡ (Shipped in v1.0.0)
- **Description:** `RevoltDataSource` (an `IDeferredDataSource` of the core) and `RevoltStageTransport` (its `IStageTransport`), on the hook ASpecification added in 2.6.0. Inside a resolution stage the `DataResolver` hands every pending request of a transport to one `fetchAll()`, which runs each source's closure in its own Fiber (`RevoltRunner`, over `Amp\async()`), at most `maxConcurrency` at a time (8 by default; `RevoltStageTransport::shared()` or one transport per source), with the source's own `timeout` and one `Amp\TimeoutCancellation` of the resolution deadline shared by every request, so the unfinished ones are cancelled at it. The failures are the core's: `DataFetchException::sourceFailed()` for an exception of the closure, `timeout()` for the source's limit, `resolutionTimeout()` for the deadline, a `DataPendingException` passed through as it is. The closure follows the arity rule of `CallableDataSource` (`fn(array $p)` works) and may take the request's `Amp\Cancellation`, return an `Amp\Future` or suspend. The application's Revolt loop is used: outside a Fiber the wait runs the loop, inside one it suspends only that Fiber. `fetch()` is the synchronous path outside a resolution scope. `RevoltRunner` is a copy of the runner of `antevemus/alinq-revolt`, kept as a copy so neither package depends on the other.
- **Goal:** Milestone 15b of the ASpecification roadmap, its satellite part: the core fetches HTTP sources together (`curl_multi`) but runs other sources one after the other; with this package the non-HTTP sources behind non-blocking clients overlap their waits. Measured: one resolution of 16 sources of 200 ms takes 3 227 ms as `CallableDataSource` with a blocking client, 215 ms as `RevoltDataSource` with the Amp HTTP client and `maxConcurrency` 16 (428 ms with the default 8), 210 ms through the core's `HttpDataSource` batch, and 3 244 ms as `RevoltDataSource` with the blocking client, because a Fiber only gains time on non-blocking I/O. The same trees give the same verdicts and failures through `CallableDataSource`, `HttpDataSource` and `RevoltDataSource`.

---

## 🔭 Planned Milestones and Deliberate Limits

### 2. No HTTP Source of Its Own 🚫 (Out of scope)
- **Description:** A source over the Amp HTTP client would duplicate the core's `HttpDataSource`, whose `curl_multi` batch already fetches HTTP concurrently, with no dependency and under PHP-FPM (measured above: 210 ms for 16 calls of 200 ms). A closure of `RevoltDataSource` can call the Amp HTTP client when its features (HTTP/2, streaming, its own interceptors) are needed.

### 3. Retries and Circuit Breaker 🧯 (In the core)
- **Description:** Not here: they belong to the core, with its milestone 16, for every kind of source at once (HTTP, callable and deferred). Meanwhile a closure can retry on its own, inside its `timeout`.

### 4. A Satellite for Another Event Loop 🔌 (Not planned)
- **Description:** A satellite for ReactPHP (or any other event loop) would be a separate package on the same `IDeferredDataSource`/`IStageTransport` hook of the core, of the size of this one (a source, a transport and a runner), touching neither the core nor this package. It is not planned and would be built on demand: a ReactPHP application already runs this package on the same loop through the official adapter `revolt/event-loop-adapter-react`. Why this package is built on Revolt and Amp, and the contract such a package implements: [Architecture](docs/en/internals/architecture.md).
