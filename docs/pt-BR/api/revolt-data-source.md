# RevoltDataSource e o Transporte

```text
new RevoltDataSource(
    Closure $fetch,                          // (array $parameters, DataRequest $request, Amp\Cancellation $cancellation): mixed
    int $cost = 0,                           // custo relativo: a política tiered busca primeiro as fontes mais baratas
    ?int $ttl = null,                        // segundos em que um valor pode ficar em cache entre resoluções (null ou 0: nunca)
    float $timeout = 5.0,                    // limite de tempo de uma requisição, em segundos, a partir do seu início
    ?RevoltStageTransport $transport = null, // null: RevoltStageTransport::shared()
)

new RevoltStageTransport(int $maxConcurrency = 8)
RevoltStageTransport::shared(): RevoltStageTransport
```

A `RevoltDataSource` implementa a `IDeferredDataSource` do núcleo (e portanto a `IDataSource`): é registrada por nome num `DataSourceRegistry` e nomeada por folhas `Spec::external()`, como qualquer fonte. Um `ttl` negativo, e um `timeout` que não seja um número positivo e finito de segundos, são recusados quando a fonte é construída (`InvalidArgumentException`); um `maxConcurrency` abaixo de 1, quando o transporte é construído. `getCost()`, `getTtl()`, `getTimeout()` e `getTransport()` os devolvem; `getMaxConcurrency()`, o limite de um transporte.

Custo, TTL, cache e deduplicação se comportam como para qualquer fonte do núcleo: duas folhas pedindo o mesmo dado causam uma requisição; um valor vai para o cache com o TTL da sua fonte (`new DataResolver($registry, new InMemoryDataCache())`) e uma requisição respondida pelo cache nunca chega à closure; uma falha nunca vai para o cache; no `tiered()` cada custo é um estágio próprio, do mais barato para o mais caro, e um veredito decidido pula os estágios caros.

## A closure

A closure recebe os parâmetros da requisição (ordenados por nome), como o callable de uma `CallableDataSource`, então `fn(array $p)` funciona; com um segundo parâmetro, a `DataRequest` (o seu `source` é o nome com que a fonte foi registrada); com um terceiro (ou quando é variádica), o `Amp\Cancellation` da requisição, acionado quando o seu limite de tempo ou o prazo da resolução se esgota: passe-o ao cliente do Amp, ao `Amp\delay()`, ao SDK. Ela roda numa Fiber sob o event loop: pode suspender, e pode devolver um valor ou um `Amp\Future` (aguardado com o mesmo cancelamento).

<!-- tested: closure -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\Future;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\async;
use function Amp\delay;

$byParameters = new RevoltDataSource(fn(array $p): string => "account {$p['account']}");
$withTheRequest = new RevoltDataSource(fn(array $p, DataRequest $request): string => $request->source);
$withTheCancellation = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): bool {
    delay(0.01, cancellation: $cancellation); // o cliente do Amp o recebe do mesmo jeito
    return $cancellation->isRequested();
});
$returningAFuture = new RevoltDataSource(fn(array $p): Future => async(fn(): int => 42));

// fetch(): o caminho síncrono, fora de uma resolução (aqui, para mostrar o que cada closure responde)
$request = new DataRequest('ledger.balance', ['account' => 'A-1']);
$byParameters->fetch($request); // 'account A-1'
$withTheRequest->fetch($request); // 'ledger.balance'
$withTheCancellation->fetch($request); // false
$returningAFuture->fetch($request); // 42
```

Dentro de uma resolução o resolvedor nunca chama `fetch()`: ele entrega toda requisição pendente do estágio que divide um transporte a um único `fetchAll()`. `fetch()` é o caminho de uma folha avaliada fora de um resolvedor (`$spec->evaluate($candidate)`, pelo registro padrão) e das requisições avulsas da política `sequential()`: uma requisição pelo transporte da fonte, o seu valor devolvido ou a sua falha lançada. Fora de uma Fiber ele roda o event loop; dentro de uma, suspende aquela Fiber.

## Falhas

A falha de uma requisição é o erro de avaliação da folha que precisava dela, como para toda fonte do núcleo: no `evaluate()` a folha fica insatisfeita com `isError`, a mensagem genérica, `metadata['evaluation_error'] = true`, a exceção em `$result->exception` para o log; `getFailedRequests()` lista a requisição; as outras requisições do estágio não são afetadas. As exceções são as do núcleo, com as mesmas fábricas das suas fontes HTTP e callable, então a mesma árvore dá os mesmos vereditos e as mesmas falhas por uma `CallableDataSource`, uma `HttpDataSource` e uma `RevoltDataSource` (os testes de paridade de [Testes](../quality/tests.md)):

| O que falhou | A exceção | Como o núcleo faz para |
| :--- | :--- | :--- |
| a closure lançou (ou o seu `Future` falhou) | `DataFetchException::sourceFailed()`: `Data source "<name>" failed (<class>).`, a original como `getPrevious()` | uma `CallableDataSource` que lança |
| a closure lançou uma `DataPendingException` | essa exceção, como está: a requisição é listada por `getPendingRequests()` e `$result->isPending()` é verdadeiro | uma `AsyncDataSource` cujo resultado não está pronto |
| o próprio `timeout` da fonte se esgotou | `DataFetchException::timeout()`: `Data source "<name>" did not answer within its time limit of <s> s.` | uma `HttpDataSource` que passou do seu próprio limite de tempo |
| o prazo da resolução se esgotou | `DataFetchException::resolutionTimeout()`: `The time limit of the resolution was reached before data source "<name>" answered.` | qualquer fonte quando `ResolutionPolicy::...(timeout: ...)` se esgota |

As mensagens levam só o nome da fonte e a classe da exceção original, nunca o valor de um parâmetro nem a mensagem original (que fica em `getPrevious()`, para o log).

<!-- tested: failures -->
```php
<?php

declare(strict_types=1);

use Amp\Cancellation;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

$offline = new RevoltDataSource(fn(array $p): float => throw new \RuntimeException("ledger offline for {$p['account']}"));
$slow = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): float {
    delay(1.0, cancellation: $cancellation);
    return 2500.0;
}, timeout: 0.05);
$notReady = new RevoltDataSource(fn(array $p): string => throw new DataPendingException('bureau.analysis', 'T-1'));

$request = new DataRequest('ledger.balance', ['account' => 'A-1']);
$offline->fetch($request); // throws DataFetchException: Data source "ledger.balance" failed (RuntimeException).
$slow->fetch($request); // throws DataFetchException: Data source "ledger.balance" did not answer within its time limit of 0.050 s.
$notReady->fetch(new DataRequest('bureau.analysis', [])); // throws DataPendingException: Data source "bureau.analysis" accepted the request; its result is not ready yet.

// O prazo da resolução: as requisições ainda rodando são canceladas nele
$patient = new RevoltDataSource(function (array $p, DataRequest $request, Cancellation $cancellation): float {
    delay(1.0, cancellation: $cancellation);
    return 2500.0;
}, timeout: 5.0);
$registry = (new DataSourceRegistry())->register('ledger.balance', $patient);
$rule = Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000));
$result = (new DataResolver($registry))->evaluate($rule, (object) ['account' => 'A-1'], ResolutionPolicy::concurrent(timeout: 0.05));

$result->isError; // true
$result->exception->getMessage(); // 'The time limit of the resolution was reached before data source "ledger.balance" answered.'
$result->failures[0]->metadata['evaluation_error']; // true
```

O prazo é o instante que o núcleo passa ao `fetchAll()`: um único `Amp\TimeoutCancellation` do tempo restante é compartilhado por todas as requisições da chamada, então as inacabadas são canceladas nele e o `fetchAll()` retorna nesse momento, incluídas as requisições que ainda esperavam uma vaga (as suas closures nunca rodam). Com menos tempo restante que o orçamento mínimo do núcleo (`CurlMultiTransport::MIN_START_BUDGET`, 10 ms), nada é iniciado e toda requisição falha com `resolutionTimeout()`, como faz o lote `curl_multi` do núcleo. Um valor que chegou antes do prazo é mantido. Uma closure que ignora o seu cancelamento continua rodando em segundo plano até retornar, segurando a sua vaga, mas a sua requisição já falhou.

## O transporte e o `maxConcurrency`

Um `RevoltStageTransport` roda no máximo `maxConcurrency` requisições ao mesmo tempo; as outras esperam uma vaga livre, na ordem das chaves, e começam conforme as vagas se liberam, então N requisições de mesma duração d levam cerca de ⌈N / maxConcurrency⌉ × d. O limite conta as closures realmente rodando, em todo `fetchAll()` que passa pelo transporte, incluídas duas resoluções rodando ao mesmo tempo em duas Fibers. Uma fonte construída sem transporte usa `RevoltStageTransport::shared()`, uma instância única do processo com limite 8, criada no primeiro uso. Dê a uma fonte o seu próprio transporte quando ela precisar de outro limite (uma API parceira que aceita quatro chamadas por vez) ou quando as suas requisições não devem dividir vagas com as outras; toda fonte no mesmo transporte é buscada pela mesma chamada de `fetchAll()` de um estágio.

<!-- tested: transport -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;
use Antevemus\ASpecificationRevolt\RevoltStageTransport;

use function Amp\delay;

$running = 0;
$peak = 0;
$partnerApi = function (array $p) use (&$running, &$peak): bool {
    $running++;
    $peak = max($peak, $running);
    delay(0.05); // 50 ms por chamada
    $running--;
    return true;
};

// Doze consultas a um parceiro por um transporte que permite quatro chamadas por vez
$fourAtATime = new RevoltStageTransport(maxConcurrency: 4);
$registry = new DataSourceRegistry();
$rule = Spec::alwaysTrue();
foreach (range(1, 12) as $i) {
    $registry->register("partner.$i", new RevoltDataSource($partnerApi, transport: $fourAtATime));
    $rule = $rule->and(Spec::external("partner.$i", [], Spec::isTrue()));
}

$started = microtime(true);
$data = (new DataResolver($registry))->resolve($rule, (object) []);
$elapsed = microtime(true) - $started;

$peak; // 4
$data->getStageCount(); // 1
$elapsed > 0.14; // true (três rodadas de quatro chamadas de 0,05 s)
(new RevoltDataSource(fn(array $p): bool => true))->getTransport() === RevoltStageTransport::shared(); // true
RevoltStageTransport::shared()->getMaxConcurrency(); // 8
```

Uma fonte que implementa a `IDeferredDataSource` de outro jeito e nomeia um `RevoltStageTransport` também é buscada por ele, pelo seu próprio `fetch()` dentro de uma Fiber: sem limite de tempo próprio, a sua exceção mantida como está (como o núcleo mantém a exceção de uma fonte buscada uma depois da outra), com o prazo da resolução aplicado.

## Dentro de uma Fiber

Sob um servidor HTTP do Amp, um worker construído sobre o Revolt ou qualquer `Amp\async()`, a resolução suspende só a Fiber que a roda: o event loop segue atendendo o resto da aplicação enquanto as requisições esperam.

<!-- tested: fiber -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

$registry = (new DataSourceRegistry())->register('kyc.status', new RevoltDataSource(function (array $p): string {
    delay(0.05); // 50 ms de I/O não bloqueante
    return 'verified';
}));
$rule = Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified'));
$resolver = new DataResolver($registry);

$events = [];
[$approved] = await([
    async(function () use ($resolver, $rule, &$events): bool {
        $verdict = $resolver->evaluate($rule, (object) ['cpf' => '111.444.777-35'])->isSatisfied;
        $events[] = 'resolved';
        return $verdict;
    }),
    async(function () use (&$events): void {
        delay(0.01);
        $events[] = 'another request served';
    }),
]);

$approved; // true
$events; // ['another request served', 'resolved']
```
