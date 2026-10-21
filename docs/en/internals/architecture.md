# Architecture

Why this package is built on Revolt and Amp, how it plugs into the core without the core knowing any event loop, and what a package for another event loop would implement.

## Why Revolt and Amp, not ReactPHP

The reasons, the heaviest first:

1. **Fibers, not promises.** The core is synchronous: the `DataResolver` calls `fetchAll()` and uses the answers when it returns. So the wait for a request must suspend the Fiber it runs in (or run the loop, outside a Fiber) and come back with the value, and the core never sees a promise. Amp 3 was rewritten on Fibers: `Amp\async()` starts a Fiber, `Future::await()` suspends it until the value is there, and an `Amp\Cancellation` stops the wait. ReactPHP's main model is promises and callbacks; `react/async` 4 adds `await()` on Fibers, but cancellation and timeouts remain the promise API, and this package would need a layer to adapt them.
2. **Revolt is the common denominator.** Amp 3 runs on the Revolt event loop, and ReactPHP runs on it too, through the official adapter `revolt/event-loop-adapter-react`. There is no adapter the other way round. A ReactPHP application runs this package on the same loop as its own code; a package built on ReactPHP would have left the Amp applications out.
3. **Cancellation matched to the deadline.** The `Amp\Cancellation` of a request reaches the user's closure as its third parameter and goes as it is to the Amp client, to `Amp\delay()`, to an SDK. Its deadline part is one `Amp\TimeoutCancellation` of the time left in the stage, shared by every request of the call, so the deadline the core hands to `fetchAll()` is kept the way the core's own `curl_multi` batch keeps it: the same instant, the same minimum budget, the same `resolutionTimeout()`, with nothing translated in between.
4. **Weight.** Two small packages, `revolt/event-loop` and `amphp/amp`. The same on ReactPHP would be `react/event-loop`, `react/promise` and `react/async`, plus the adapter to share the loop with Amp applications.

## The core's hook knows no event loop

ASpecification 2.6.0 added two interfaces in `Antevemus\ASpecification\Resolution`: `IDeferredDataSource`, a source that names the transport that fetches it inside a resolution stage, and `IStageTransport`, whose `fetchAll()` receives every pending request of that transport in the stage. A stage fetches in three groups, always in this order and one after the other: the HTTP sources in one `curl_multi` batch, then one `fetchAll()` per transport, then the other sources. The core calls `fetchAll()` and waits for it to return; it never references Revolt, Amp or Fibers, depends on none of them and runs no event loop.

Everything about the loop lives in this package: `RevoltDataSource` is an `IDeferredDataSource` and `RevoltStageTransport` is its `IStageTransport`. The hook, in the core's documentation: [Deferred sources and stage transports](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/docs/en/resolution/deferred.md). How this package uses it: [How it works](../concepts/how-it-works.md).

## What a satellite for another event loop implements

The same hook serves any event loop. A satellite for another one implements two classes, and nothing changes in the core nor in this package:

- **A source** that implements `IDeferredDataSource`. `getTransport()` returns its transport (one transport may serve many sources: the core groups the requests of a stage by transport instance). `getCost()` and `getTtl()` work as for any source. `fetch()` stays mandatory: it is the synchronous path outside a resolution scope (a leaf evaluated by `$spec->evaluate($candidate)` without a resolver), so it returns the value or throws, whether or not the loop is already running. A source that is also an `IHttpDataSource` is fetched as HTTP, never through its transport.
- **A transport** that implements `IStageTransport::fetchAll(array $pending, ?float $deadline): array`. `$pending` maps each request key (`DataRequest::getKey()`) to `[$source, $request]`; the answer maps the same keys to the value of the request or to the `Throwable` that failed it. The transport starts the requests together and returns when every one has settled or the deadline has come: the next group of the stage starts only after it returns.

The rules of the answer, all set by the core:

- a `Throwable` in the answer fails that request only: the evaluation error of its leaf, listed by `getFailedRequests()`; any other value is a success;
- a key the transport does not answer fails with `DataFetchException::noTransportResult()`; a key it answers that was not asked is ignored;
- a `Throwable` thrown by `fetchAll()` itself fails every request of that transport, and only those: the other transports and sources of the stage are fetched as usual;
- `$deadline` is the monotonic instant of the time limit of the resolution, comparable to `CurlMultiTransport::now()`, or `null` without one: the transport stops waiting at it and answers the unfinished requests with `DataFetchException::resolutionTimeout()`; the core does not call a transport whose deadline has already passed;
- a value is cached by the core with the TTL of its source, and a failure is never cached, so the next resolution asks the transport again; a request answered by the cache never reaches the transport;
- `fetchAll()` may receive a single request: under `sequential()` each leaf reaches the transport alone, when the evaluation reaches it.

To give the same failures as the core's own sources, map them with the factories of `DataFetchException`, as `RevoltStageTransport` does (`<name>` is `$request->source`, the name the source was registered under):

| What failed | The answer | As the core does for |
| :--- | :--- | :--- |
| the user's code threw | `DataFetchException::sourceFailed(<name>, $e)`, the original as `getPrevious()` | a `CallableDataSource` that throws |
| the user's code threw a `DataPendingException` | that exception, as it is | an `AsyncDataSource` whose result is not ready |
| the source's own time limit ran out | `DataFetchException::timeout(<name>, <seconds>)` | an `HttpDataSource` past its own time limit |
| the resolution deadline ran out | `DataFetchException::resolutionTimeout(<name>)`; with less than `CurlMultiTransport::MIN_START_BUDGET` (10 ms) left, nothing started and every request answered with it | the core's `curl_multi` batch |

These factories write the name of the source and the class of the original exception only, never a parameter value. The parity tests of [Tests](../quality/tests.md) compare the answers of this package with the core's sources; a satellite for another loop can reuse their approach.

## ReactPHP today

A ReactPHP application uses this package as it is: with `revolt/event-loop-adapter-react` installed, ReactPHP's loop runs on the Revolt loop, and the Fibers of `RevoltStageTransport` share it with the application's own code. A dedicated `antevemus/aspecification-react` would be a new package of the size of this one (a source, a transport and a runner) on the same core hook, touching neither the core nor this package. It is not planned and would be built on demand: [Roadmap](../project/roadmap.md).
