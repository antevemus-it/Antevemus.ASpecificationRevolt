<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Unit;

use Amp\Cancellation;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;
use Antevemus\ASpecificationRevolt\Tests\Support\ResolutionSummary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\delay;

/**
 * Parity of answers: the same specification trees over the same battery of answers
 * (values, a failure, a time limit, a missing datum, an unknown source, a candidate without the parameter or
 * with a null one),
 * through CallableDataSource and through RevoltDataSource, under the three policies of the resolver, give the
 * same verdicts, the same evaluation errors, flags, reasons and codes, the same exception class and source
 * name, the same failed requests and the same stage and fetch counts. The HTTP leg of the parity
 * (HttpDataSource against the local Amp server) is tests/Http/HttpParityTest.php.
 *
 * The time limit is the one case whose message differs by nature: a RevoltDataSource has a time limit of its
 * own (DataFetchException::timeout(), the factory of an HTTP source's limit), a CallableDataSource has none,
 * so its battery fails the same request through its own client's time-out exception
 * (DataFetchException::sourceFailed()): the class, the source name and everything the evaluation reports are
 * the same, the message is not compared.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Unit
 */
final class ParityTest extends TestCase
{
    /** The answers of the battery, by source and document; '!' fails the request, '~' runs out of time. */
    private const ANSWERS = [
        'bureau.score' => ['111' => 720, '222' => 540, '333' => null],
        'kyc.status' => ['111' => 'verified', '222' => 'pending', '333' => 'verified'],
        'sanctions.hit' => ['111' => false, '222' => '!', '333' => false],
        'fraud.check' => ['111' => false, '222' => false, '333' => '~'],
    ];

    /** The cost of each source, so the tiered policy has stages to skip. */
    private const COSTS = ['bureau.score' => 10, 'kyc.status' => 1, 'sanctions.hit' => 5, 'fraud.check' => 20];

    public function testCallableAndRevoltSourcesAnswerTheSame(): void
    {
        $callable = self::registry(revolt: false);
        $revolt = self::registry(revolt: true);
        $compared = 0;
        $errors = 0;
        foreach (self::trees() as $name => $tree) {
            foreach (self::candidates() as $who => $candidate) {
                foreach (['concurrent' => ResolutionPolicy::concurrent(), 'tiered' => ResolutionPolicy::tiered(), 'sequential' => ResolutionPolicy::sequential()] as $policyName => $policy) {
                    $expected = ResolutionSummary::of($callable, $tree, $candidate, $policy);
                    $actual = ResolutionSummary::of($revolt, $tree, $candidate, $policy);
                    $this->assertSame($expected, $actual, "tree $name, candidate $who, policy $policyName");
                    $compared++;
                    $errors += $expected['error'] ? 1 : 0;
                }
            }
        }
        $this->assertSame(10 * 5 * 3, $compared);
        $this->assertGreaterThan(30, $errors, 'the battery exercises the evaluation errors');
        delay(0.001);
    }

    public function testTheBatteryCoversEveryKindOfAnswer(): void
    {
        $revolt = self::registry(revolt: true);
        $resolver = new DataResolver($revolt);
        $failure = static fn(string $source, string $cpf): ?\Throwable => $resolver->resolve(Spec::external($source, ['document' => 'cpf'], Spec::alwaysTrue()), (object) ['cpf' => $cpf])->getError(new DataRequest($source, ['document' => $cpf]));

        $this->assertNull($failure('bureau.score', '111'));
        $this->assertSame('Data source "sanctions.hit" failed (RuntimeException).', $failure('sanctions.hit', '222')?->getMessage());
        $this->assertSame('Data source "fraud.check" did not answer within its time limit of 0.020 s.', $failure('fraud.check', '333')?->getMessage());
        $this->assertInstanceOf(DataSourceNotFoundException::class, $failure('ghost.source', '111'));
        $this->assertTrue($resolver->evaluate(Spec::external('bureau.score', ['document' => 'cpf'], Spec::isNull()), (object) ['cpf' => '333'])->isSatisfied, 'a missing datum is a null value');
        $leaf = Spec::external('bureau.score', ['document' => 'cpf'], Spec::isNull());
        $data = $resolver->resolve($leaf, $candidate = (object) ['name' => 'no cpf']);
        $this->assertTrue($data->evaluate($leaf, $candidate)->isError, 'a candidate without the parameter: an evaluation error of the leaf');
        $this->assertSame([], $data->getRequests(), 'and no request');
        $data = $resolver->resolve($leaf, $candidate = (object) ['cpf' => null]);
        $result = $data->evaluate($leaf, $candidate);
        $this->assertFalse($result->isError || $result->isSatisfied, 'a null parameter: a rule failure');
        $this->assertSame([], $data->getRequests(), 'and no request');
        delay(0.001);
    }

    private static function registry(bool $revolt): DataSourceRegistry
    {
        $registry = new DataSourceRegistry();
        $transport = new RevoltStageTransport();
        foreach (self::ANSWERS as $source => $answers) {
            $answer = static function (array $p) use ($answers, $revolt): mixed {
                $value = $answers[$p['document']];
                if ($value === '!') {
                    throw new RuntimeException('provider error for ' . $p['document']);
                }
                if ($value === '~') {
                    if ($revolt) {
                        delay(1.0, cancellation: func_get_arg(2));
                    }
                    throw new RuntimeException('the client of the provider timed out');
                }

                return $value;
            };
            $registry->register($source, $revolt
                ? new RevoltDataSource(static function (array $p, DataRequest $r, Cancellation $c) use ($answer): mixed {
                    delay(0.001); // suspends, so the requests of a stage finish in any order
                    return $answer($p, $r, $c);
                }, cost: self::COSTS[$source], timeout: 0.02, transport: $transport)
                : new CallableDataSource($answer, cost: self::COSTS[$source]));
        }

        return $registry;
    }

    /** @return array<string, ISpecification> */
    private static function trees(): array
    {
        $score = Spec::external('bureau.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))->withCode('SCORE');
        $kyc = Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified'))->withCode('KYC');
        $clean = Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isFalse())->withCode('SANCTIONS');
        $fraud = Spec::external('fraud.check', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD');
        $ghost = Spec::external('ghost.source', ['document' => 'cpf'], Spec::isTrue());
        $missing = Spec::external('bureau.score', ['document' => 'cpf'], Spec::isNull());

        return [
            'score' => $score,
            'score and kyc' => $score->and($kyc),
            'clean' => $clean,
            'not a hit' => Spec::not(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isTrue())),
            'fraud or kyc' => $fraud->or($kyc),
            'kyc or fraud' => $kyc->or($fraud),
            'ghost and score' => $ghost->and($score),
            'missing datum' => $missing->or($score),
            'everything' => $score->and(Spec::not(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isTrue())))->or($kyc->and($fraud)),
            'all four' => $kyc->and($clean)->and($score)->and($fraud),
        ];
    }

    /** @return array<string, object> */
    private static function candidates(): array
    {
        return [
            'c1' => (object) ['cpf' => '111'],
            'c2' => (object) ['cpf' => '222'],
            'c3' => (object) ['cpf' => '333'],
            'no cpf' => (object) ['name' => 'no cpf'],
            'null cpf' => (object) ['cpf' => null],
        ];
    }
}
