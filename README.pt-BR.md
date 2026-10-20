# Antevemus ASpecification Revolt

[![CI](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/actions/workflows/tests.yml/badge.svg)](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/badge/release-v1.0.0-blue.svg)](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/releases)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Tests Passing](https://img.shields.io/badge/tests-85%20passed%20%7C%204974%20assertions-success.svg)](docs/pt-BR/quality/tests.md)
[![Core: ASpecification](https://img.shields.io/badge/Core-Antevemus.ASpecification-orange)](https://github.com/antevemus-it/Antevemus.ASpecification)

> **O satélite Revolt/Fibers do [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) para PHP 8.2+**  
> `RevoltDataSource` e `RevoltStageTransport` buscam os dados externos não HTTP de um estágio de resolução de forma concorrente sob o event loop do Revolt (Fibers, futures do Amp), pelo gancho `IDeferredDataSource`/`IStageTransport` do núcleo: um limite de concorrência, um limite de tempo por fonte e o prazo da resolução, com as mesmas falhas das próprias fontes do núcleo. Para dados atrás de clientes não bloqueantes (Amp, SDKs assíncronos).

---

[🇺🇸 English](README.md) • [🇧🇷 Português (Brasil)](README.pt-BR.md)

---

A documentação vive em [`docs/pt-BR`](docs/pt-BR/index.md) (português do Brasil) e [`docs/en`](docs/en/index.md) (inglês), uma página por tópico, no formato Docs Kit (um `docs-nav.json` por idioma), e todo bloco PHP das páginas é executado pela suíte de testes.

---

## 🚀 Início Rápido

O mesmo bloco abre [Primeiros Passos](docs/pt-BR/getting-started/index.md), e a suíte de testes o executa com as respostas escritas nos seus comentários:

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

---

## 📦 Instalação

Instale via Composer: `composer require antevemus/aspecification-revolt`. Requisitos: PHP 8.2 ou superior; instalados junto: [`antevemus/aspecification`](https://github.com/antevemus-it/Antevemus.ASpecification) `^2.6` (o núcleo, mesmo autor; 2.6.0 pelo gancho `IDeferredDataSource`/`IStageTransport`), [`revolt/event-loop`](https://github.com/revoltphp/event-loop) `^1.0` e [`amphp/amp`](https://github.com/amphp/amp) `^3.0`. Nenhuma extensão do PHP é exigida.

Uma Fiber só ganha tempo enquanto o código dentro dela espera I/O **não bloqueante**: chamadas bloqueantes (`file_get_contents()`, `curl_exec()`, PDO) e trabalho de CPU rodam uma depois da outra, qualquer que seja a concorrência. Medido contra um servidor HTTP local que responde em 200 ms, uma resolução de 1, 4 e 16 fontes (mediana de 3 rodadas, PHP 8.4.13):

| Fontes × 200 ms | CallableDataSource, bloqueante | RevoltDataSource (8), cliente do Amp | RevoltDataSource (16), cliente do Amp | HttpDataSource (curl_multi) | RevoltDataSource (16), bloqueante |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 203 ms | 202 ms | 203 ms | 201 ms | 202 ms |
| 4 | 809 ms | 208 ms | 211 ms | 205 ms | 809 ms |
| 16 | 3 227 ms | 428 ms | 215 ms | 210 ms | 3 244 ms |

Uma `RevoltDataSource` só ganha com o cliente não bloqueante (as colunas do Amp); com um cliente bloqueante ela leva o mesmo que as callables, e o mesmo vale para uma closure que só calcula. O próprio lote da `HttpDataSource` do núcleo é a referência para HTTP. Como foi medido, o caso só de CPU e os testes que o afirmam: [Testes](docs/pt-BR/quality/tests.md).

---

## 📚 Documentação

**Primeiros passos**

- [Instalação e início rápido](docs/pt-BR/getting-started/index.md) · [en](docs/en/getting-started/index.md): Composer, os requisitos e o Início Rápido.

**Conceitos**

- [Como funciona](docs/pt-BR/concepts/how-it-works.md) · [en](docs/en/concepts/how-it-works.md): Os três grupos de um estágio de resolução, o transporte e o runner, o event loop, e por que Fibers só ganham tempo em I/O não bloqueante.

**Referência da API**

- [RevoltDataSource e o transporte](docs/pt-BR/api/revolt-data-source.md) · [en](docs/en/api/revolt-data-source.md): O construtor, a closure, custo e TTL, o limite de tempo, as falhas e as suas exceções, o `maxConcurrency`, o transporte compartilhado e o uso dentro de uma Fiber.

**Qualidade**

- [Testes](docs/pt-BR/quality/tests.md) · [en](docs/en/quality/tests.md): O relatório do PHPUnit, a paridade com as fontes do núcleo, a tabela de tempos medida e o teste de mutação.

**Projeto**

- [Roadmap](docs/pt-BR/project/roadmap.md) · [en](docs/en/project/roadmap.md): A versão entregue e o que vem depois; a visão por marcos está no ROADMAP.md.

---

## 🧪 Qualidade

- **Testes:** 85 testes PHPUnit (`vendor/bin/phpunit`) e 4974 asserções, 100% passando no PHP 8.4 (e na 8.2; a CI roda 8.2, 8.3 e 8.4): a fonte, o transporte e o runner, a paridade com as fontes do núcleo, propriedades sobre árvores aleatórias, a prova de ganho contra um servidor HTTP local, e os blocos de código da documentação.
- **Paridade:** as mesmas árvores dão os mesmos vereditos, erros de avaliação, requisições falhas, classes de exceção e nomes de fonte por `CallableDataSource`, `HttpDataSource` e `RevoltDataSource` (150 comparações com a fonte callable sob as três políticas, 52 contra o servidor local).
- **Propriedades:** 300 árvores aleatórias semeadas sobre fontes Revolt em 1 a 3 transportes misturadas com fontes callable: os mesmos vereditos que por fontes callable, sob toda política; cada requisição buscada uma vez; cada transporte chamado no máximo uma vez por estágio.
- **Teste de mutação:** Infection sobre todo o `src/`, MSI 96,69% (4 mutantes escapados, todos equivalentes); o workflow de Qualidade exige 95%.
- **O relatório**, a tabela de tempos e a rodada de mutação: [Testes](docs/pt-BR/quality/tests.md).

---

## 🗺️ Roadmap e Ecossistema

A versão entregue e o que vem depois: [Roadmap](docs/pt-BR/project/roadmap.md). A visão por marcos: 👉 **[ROADMAP.md](ROADMAP.md)**. O núcleo e o roadmap dele: [Antevemus.ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification). O companheiro assíncrono do ALinq, cujo runner este pacote copia: [Antevemus.AlinqRevolt](https://github.com/antevemus-it/Antevemus.AlinqRevolt).

---

## 📄 Licença e Autores

Este software é open-source, sob a **[Licença MIT](LICENSE)**.

- **Autor:** Heliton Junior (CTO) — [`contato@antevemus.com.br`](mailto:contato@antevemus.com.br)
- **Organização:** [Antevemus Soluções Inovadoras em TI Ltda.](https://antevemus.com.br)
- **Copyright:** Copyright (c) 2026 Antevemus Soluções Inovadoras em TI Ltda.
