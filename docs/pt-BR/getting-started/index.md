# Primeiros Passos

## 📦 Instalação

Instale via Composer:

```bash
composer require antevemus/aspecification-revolt
```

Requisitos: PHP 8.2 ou superior. Instalados junto: [`antevemus/aspecification`](https://github.com/antevemus-it/Antevemus.ASpecification) `^2.6` (o núcleo, do mesmo autor; a 2.6.0 é a primeira versão com o gancho em que este pacote se encaixa, `IDeferredDataSource` e `IStageTransport`), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` (o event loop) e [`amphp/amp`](https://github.com/amphp/amp) `^3.0` (futures, cancelamentos, `delay()`). Nenhuma extensão do PHP é exigida (a `HttpDataSource` do núcleo precisa do `ext-curl`; este pacote não). O pacote usa o event loop do Revolt da aplicação quando há um e nunca cria nem substitui um driver.

Para que serve: os dados externos **não HTTP** de uma especificação (`Spec::external()`) que um cliente não bloqueante consegue buscar: um cliente do Amp ou do Revolt, um SDK assíncrono, um driver que suspende uma Fiber. O núcleo já busca as suas fontes HTTP juntas (`curl_multi`) e roda uma `CallableDataSource` de forma síncrona, uma depois da outra; uma `RevoltDataSource` é buscada com as outras fontes Revolt do seu estágio, ao mesmo tempo. Uma Fiber só ganha tempo enquanto o código dentro dela espera I/O não bloqueante; chamadas bloqueantes (`file_get_contents()`, `curl_exec()`, PDO) e trabalho de CPU rodam uma depois da outra. Veja [Como funciona](../concepts/how-it-works.md).

### Repositório Git VCS (Ambientes de Desenvolvimento ou Privados)

Para usar direto do GitHub antes do Packagist ou junto com ele:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/antevemus-it/Antevemus.ASpecificationRevolt.git"
        }
    ],
    "require": {
        "antevemus/aspecification-revolt": "^1.0"
    }
}
```

## 🚀 Início Rápido

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

// Três serviços que respondem em 100 ms; Amp\delay() faz o papel de um cliente HTTP do Amp ou de um SDK assíncrono
$service = fn(mixed $answer): RevoltDataSource => new RevoltDataSource(
    function (array $p, DataRequest $request, Cancellation $cancellation) use ($answer): mixed {
        delay(0.1, cancellation: $cancellation); // 100 ms de I/O não bloqueante
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
$elapsed < 0.25; // true (as três esperas se sobrepuseram: cerca de 0,1 s, não 0,3 s)
```

A regra é uma especificação comum do núcleo: `Spec::external()` declara o dado, o `DataResolver` reúne as requisições da árvore inteira, entrega as três requisições Revolt do estágio a uma só chamada de `RevoltStageTransport::fetchAll()` (o transporte compartilhado, já que nenhum foi dado), que roda as três closures em três Fibers, e só então avalia a árvore. A closure, o limite de tempo, as falhas e o transporte: [RevoltDataSource e o transporte](../api/revolt-data-source.md).
