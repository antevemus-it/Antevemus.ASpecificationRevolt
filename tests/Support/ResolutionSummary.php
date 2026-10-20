<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Support;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Resolution\ResolvedData;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * Everything a resolution and the evaluation through it report, as one comparable array (the parity of
 * answers): the verdict, the evaluation error and pending flags, the evaluation_error flag
 * of each failure, the reasons and codes, the class and source name of the exception, the failed, known and
 * skipped requests, the stage and fetch counts, and the class and source name of each failed request.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Support
 */
final class ResolutionSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function of(DataSourceRegistry $registry, ISpecification $tree, object $candidate, ResolutionPolicy $policy): array
    {
        $data = (new DataResolver($registry))->resolve($tree, $candidate, $policy);
        $result = $data->evaluate($tree, $candidate);

        return [
            'satisfied' => $result->isSatisfied,
            'error' => $result->isError,
            'pending' => $result->isPending(),
            'flags' => array_map(static fn($failure): bool => ($failure->metadata['evaluation_error'] ?? false) === true, $result->failures),
            'reasons' => $result->getReasons(),
            'codes' => $result->getCodes(),
            'exception' => self::describe($result),
            'failed' => self::keys($data->getFailedRequests()),
            'requests' => self::keys($data->getRequests()),
            'skipped' => self::keys($data->getSkippedRequests()),
            'stages' => $data->getStageCount(),
            'fetches' => $data->getFetchCount(),
            'errors' => self::errors($data),
        ];
    }

    /** @return array{string, ?string}|null */
    private static function describe(SpecificationResult $result): ?array
    {
        $e = $result->exception;
        if ($e === null) {
            return null;
        }

        return [$e::class, $e instanceof DataFetchException || $e instanceof DataSourceNotFoundException ? $e->getSourceName() : null];
    }

    /** @return array<string, array{string, ?string}> the class and source name of each failed request */
    private static function errors(ResolvedData $data): array
    {
        $errors = [];
        foreach ($data->getFailedRequests() as $request) {
            $e = $data->getError($request);
            $errors[$request->getKey()] = [$e === null ? 'none' : $e::class, $e instanceof DataFetchException ? $e->getSourceName() : null];
        }
        ksort($errors);

        return $errors;
    }

    /**
     * @param list<DataRequest> $requests
     * @return list<string>
     */
    private static function keys(array $requests): array
    {
        $keys = array_map(static fn(DataRequest $r): string => $r->getKey(), $requests);
        sort($keys);

        return $keys;
    }
}
