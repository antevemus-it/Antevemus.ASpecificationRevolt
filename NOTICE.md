Antevemus ASpecification Revolt
Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
License: MIT (SPDX: MIT) — see LICENSE.

Website: https://antevemus.com.br
Email: contato@antevemus.com.br

-------------------------------------------------------------------------------
1. SCOPE & PURPOSE
-------------------------------------------------------------------------------

Antevemus ASpecification Revolt is the Revolt/Fibers satellite of Antevemus
ASpecification (antevemus/aspecification) for PHP 8.2+. Its RevoltDataSource
and RevoltStageTransport plug into the IDeferredDataSource/IStageTransport hook
of the core (2.6.0): the non-HTTP requests of a resolution stage run together
under the Revolt event loop, each closure in its own Fiber through Amp
futures, with a concurrency limit, a time limit per source and the deadline of
the resolution, and fail with the core's own exceptions.

-------------------------------------------------------------------------------
2. PROVENANCE — NOTHING PORTED
-------------------------------------------------------------------------------

No source code of any third-party project was copied, ported or translated
into this package. It is an independent implementation, written by Antevemus
Soluções Inovadoras em TI Ltda. on the public APIs of its runtime dependencies
(section 5); the only code it did not write for this package is first-party
code of the same author, copied on purpose (section 3).

-------------------------------------------------------------------------------
3. FIRST-PARTY CODE COPIED ON PURPOSE
-------------------------------------------------------------------------------

src/RevoltRunner.php is a copy of the RevoltRunner of Antevemus ALinq Revolt
(antevemus/alinq-revolt 1.0.0), by the same author and under the same MIT
License, with only its namespace and the package of its docblock changed; its
tests (tests/Unit/RevoltRunnerTest.php) are copied with it. It is kept as a
copy on purpose: neither satellite depends on the other, and each depends only
on its own core. The test fixtures tests/Support/LatencyServer.php,
tests/Support/latency-server.php, tests/Support/timing-table.php and the
documentation guards tests/Unit/DocumentationTreeTest.php and
tests/Unit/DocumentationBlocksTest.php are adapted from the same package.

    Origin: RevoltRunner (antevemus/alinq-revolt 1.0.0)
    Originator: Antevemus Soluções Inovadoras em TI Ltda.
    License: MIT License
    Website: https://github.com/antevemus-it/Antevemus.AlinqRevolt

-------------------------------------------------------------------------------
4. CONCEPTUAL INSPIRATIONS
-------------------------------------------------------------------------------

The contract it implements (deferred sources fetched by a stage transport, the
three groups of a stage, the failure factories) is the one of the core package,
by the same author. The failure mapping follows the core's own sources:
HttpDataSource for the time limit of a source, CallableDataSource for an
exception of the closure, AsyncDataSource for a result that is not ready yet.

    Conceptual Origin: IDeferredDataSource / IStageTransport (antevemus/aspecification 2.6.0)
    Originator: Antevemus Soluções Inovadoras em TI Ltda.
    License: MIT License
    Website: https://github.com/antevemus-it/Antevemus.ASpecification

-------------------------------------------------------------------------------
5. THIRD-PARTY COMPONENTS & LICENSES
-------------------------------------------------------------------------------

See the THIRD_PARTY_NOTICES.md file for the runtime dependencies (revolt/
event-loop and amphp/amp, MIT; the core, MIT) and the development tools.
