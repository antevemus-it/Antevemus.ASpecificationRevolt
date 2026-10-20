# Como Funciona

## O gancho do núcleo: três grupos por estágio

O `DataResolver` do [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) busca os dados externos de uma árvore de especificações inteira antes de avaliá-la, estágio por estágio (um estágio no `concurrent()`, um por custo no `tiered()`, um por folha no `sequential()`). Desde a 2.6.0 um estágio busca as suas requisições pendentes em três grupos, sempre nesta ordem ([Fontes adiadas e transportes de estágio](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/docs/pt-BR/resolution/deferred.md) na documentação do núcleo):

1. **as fontes HTTP** (`HttpDataSource`), juntas, num único lote `curl_multi`;
2. **as fontes adiadas** (`IDeferredDataSource`), agrupadas pelo transporte que nomeiam (`getTransport()`): uma chamada de `IStageTransport::fetchAll()` por transporte, com toda requisição pendente daquele transporte no estágio;
3. **as demais fontes** (`CallableDataSource` e qualquer outra `IDataSource`), uma depois da outra.

Os grupos são **serializados pelo núcleo**: os transportes começam depois que o lote HTTP terminou, e cada um termina antes de o próximo começar. A concorrência está dentro de cada grupo. Uma `RevoltDataSource` é uma fonte adiada, e um `RevoltStageTransport` é o seu transporte.

## O transporte e o runner

`RevoltStageTransport::fetchAll()` recebe as requisições Revolt do estágio, por chave, e:

1. inicia uma Fiber por requisição, pelo seu `RevoltRunner` (`Amp\async()`), no máximo `maxConcurrency` por vez (8 por padrão); as outras esperam uma vaga livre, na ordem das chaves;
2. roda, em cada Fiber, a closure da fonte com os parâmetros da requisição, a `DataRequest` e um `Amp\Cancellation`; a closure pode devolver um valor ou um `Amp\Future`, ou suspender;
3. limita cada requisição com o `timeout` da sua fonte, contado a partir do início da requisição, e todas as requisições da chamada com o prazo da resolução (`ResolutionPolicy::concurrent(timeout: ...)`), um único `Amp\TimeoutCancellation` compartilhado por todas;
4. espera todas e responde por chave: o valor, ou a exceção que fez a requisição falhar.

O runner é o `RevoltRunner` do `antevemus/alinq-revolt` (o companheiro assíncrono do ALinq), copiado aqui de propósito, para que nenhum dos dois pacotes dependa do outro. Um transporte limita toda chamada que passa por ele: duas resoluções rodando ao mesmo tempo em duas Fibers dividem as suas vagas. `RevoltStageTransport::shared()` é o transporte de toda `RevoltDataSource` construída sem um.

<!-- tested: stage -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

$finished = [];
$registry = (new DataSourceRegistry())
    ->register('ledger.balance', new RevoltDataSource(function (array $p) use (&$finished): float {
        delay(0.06); // 60 ms de I/O não bloqueante
        $finished[] = 'ledger.balance';
        return 2500.0;
    }))
    ->register('kyc.status', new RevoltDataSource(function (array $p) use (&$finished): string {
        delay(0.04); // 40 ms
        $finished[] = 'kyc.status';
        return 'verified';
    }))
    ->register('risk.local', new CallableDataSource(function (array $p) use (&$finished): int {
        $finished[] = 'risk.local';
        return 3;
    }));

$rule = Spec::external('risk.local', ['account' => 'account'], Spec::lessThan(5))
    ->and(Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000)))
    ->and(Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified')));

$started = microtime(true);
$data = (new DataResolver($registry))->resolve($rule, (object) ['cpf' => '111.444.777-35', 'account' => 'A-1']);
$elapsed = microtime(true) - $started;

$finished; // ['kyc.status', 'ledger.balance', 'risk.local']
$data->getStageCount(); // 1
$elapsed < 0.09; // true (as duas requisições Revolt se sobrepuseram: cerca de 0,06 s, não 0,1 s)
$data->evaluate($rule, (object) ['cpf' => '111.444.777-35', 'account' => 'A-1'])->isSatisfied; // true
```

A fonte callable vem primeiro na árvore e mesmo assim roda por último: o grupo do transporte vem antes das demais fontes. Dentro do grupo as duas requisições Revolt começaram juntas, e a mais curta terminou primeiro.

## O event loop

O transporte usa o event loop do Revolt da aplicação (`Revolt\EventLoop`) e nunca cria nem substitui um driver. Esperar é `Amp\Future::await()`:

- **fora de uma Fiber** (uma requisição do PHP-FPM, um script de CLI, um job), ele roda o event loop até as requisições terminarem, e o `DataResolver::evaluate()` retorna como sempre;
- **dentro de uma Fiber** (um servidor HTTP do Amp, um worker construído sobre o Revolt, `Amp\async()`), ele suspende só aquela Fiber, e o resto da aplicação segue rodando enquanto as requisições esperam. [RevoltDataSource e o transporte](../api/revolt-data-source.md#dentro-de-uma-fiber) mostra isso.

## Fibers só ganham tempo em I/O não bloqueante

Uma Fiber é uma corrotina cooperativa na única thread do PHP: ela devolve a thread só quando espera algo que o event loop vigia (um socket de um cliente do Amp, um timer de `Amp\delay()`). Sobrepor essas esperas é todo o ganho. Uma chamada bloqueante (`file_get_contents()`, `curl_exec()`, `usleep()`, PDO) segura a thread até retornar, e trabalho de CPU nunca a devolve, então dentro de uma `RevoltDataSource` eles rodam um depois do outro, qualquer que seja o `maxConcurrency`:

<!-- tested: blocking -->
```php
<?php

declare(strict_types=1);

use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecificationRevolt\RevoltDataSource;

use function Amp\delay;

// Quatro fontes que esperam 50 ms cada, num único estágio
$fourSources = function (\Closure $wait): float {
    $registry = new DataSourceRegistry();
    $rule = Spec::alwaysTrue();
    foreach (['s1', 's2', 's3', 's4'] as $name) {
        $registry->register($name, new RevoltDataSource(function (array $p) use ($wait): bool {
            $wait();
            return true;
        }));
        $rule = $rule->and(Spec::external($name, [], Spec::isTrue()));
    }
    $started = microtime(true);
    (new DataResolver($registry))->evaluate($rule, (object) []);
    return microtime(true) - $started;
};

$nonBlocking = $fourSources(fn() => delay(0.05)); // cerca de 0,05 s: as quatro esperas se sobrepuseram
$blocking = $fourSources(fn() => usleep(50_000)); // cerca de 0,2 s: usleep() segura a thread, uma chamada depois da outra

$nonBlocking < 0.15; // true
$blocking > 0.19; // true
```

O mesmo vale pela rede: contra um servidor HTTP local que responde em 200 ms, 16 fontes levam cerca de 3 200 ms como `CallableDataSource` com um cliente bloqueante, cerca de 3 200 ms como `RevoltDataSource` com o mesmo cliente bloqueante, cerca de 200 ms como `RevoltDataSource` com o cliente HTTP do Amp e `maxConcurrency` 16, e cerca de 200 ms pelo próprio lote da `HttpDataSource` do núcleo. Uma closure que só calcula também não ganha nada. A tabela medida e os testes que a afirmam: [Testes](../quality/tests.md).

Então, para HTTP, a `HttpDataSource` do núcleo já é concorrente sem dependência nenhuma (e funciona no PHP-FPM, sem event loop); a `RevoltDataSource` é para o resto: SDKs e clientes que não bloqueiam, e chamadas HTTP que precisam do que um cliente do Amp oferece. Para trabalho de CPU, Fibers são a ferramenta errada: ele precisa de processos de trabalho.

## As classes

| Classe | Papel |
| :--- | :--- |
| `RevoltDataSource` (final) | Uma `IDeferredDataSource`: a closure, `cost`, `ttl`, `timeout` e o transporte; `fetch()` é o caminho síncrono fora de um escopo de resolução. |
| `RevoltStageTransport` (final) | Um `IStageTransport`: `fetchAll()` roda as requisições de um estágio juntas sob o loop, com `maxConcurrency`, o limite de tempo por fonte e o prazo da resolução; `shared()` é o padrão do processo inteiro. |
| `RevoltRunner` (final) | Roda closures sob o loop do Revolt com um limite de concorrência e um limite de tempo por tarefa; uma cópia do runner do `antevemus/alinq-revolt`. |
