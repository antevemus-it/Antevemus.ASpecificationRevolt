<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Support;

use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;

/**
 * A decorating IStageTransport that records every fetchAll() call (the source names handed to it, in key
 * order) and delegates to a RevoltStageTransport, which is final and cannot be extended.
 *
 * A RevoltDataSource names a RevoltStageTransport, so the sources counted here are wrapped (source()): the
 * wrapper names this decorator, and fetchAll() hands the inner RevoltDataSource of each request to the
 * delegate, which fetches it as it fetches any RevoltDataSource.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */
final class CountingStageTransport implements IStageTransport
{
    /** @var list<list<string>> the source names of each call, in key order */
    public array $calls = [];

    public function __construct(public readonly RevoltStageTransport $inner)
    {
    }

    /** A deferred source that names this decorator and is fetched as the RevoltDataSource it wraps. */
    public function source(RevoltDataSource $source): IDeferredDataSource
    {
        return new class ($source, $this) implements IDeferredDataSource {
            public function __construct(public readonly RevoltDataSource $inner, private readonly IStageTransport $transport)
            {
            }

            public function fetch(DataRequest $request): mixed
            {
                return $this->inner->fetch($request);
            }

            public function getCost(): int
            {
                return $this->inner->getCost();
            }

            public function getTtl(): ?int
            {
                return $this->inner->getTtl();
            }

            public function getTransport(): IStageTransport
            {
                return $this->transport;
            }
        };
    }

    /** {@inheritdoc} */
    public function fetchAll(array $pending, ?float $deadline): array
    {
        $this->calls[] = array_values(array_map(static fn(array $p): string => $p[1]->source, $pending));
        $unwrapped = [];
        foreach ($pending as $key => [$source, $request]) {
            $unwrapped[$key] = [property_exists($source, 'inner') ? $source->inner : $source, $request];
        }

        return $this->inner->fetchAll($unwrapped, $deadline);
    }
}
