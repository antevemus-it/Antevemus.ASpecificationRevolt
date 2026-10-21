# Arquitetura

Por que este pacote é construído sobre o Revolt e o Amp, como ele se encaixa no núcleo sem que o núcleo conheça event loop algum, e o que um pacote para outro event loop implementaria.

## Por que Revolt e Amp, e não ReactPHP

Os motivos, do mais pesado para o mais leve:

1. **Fibers, não promises.** O núcleo é síncrono: o `DataResolver` chama `fetchAll()` e usa as respostas quando ele retorna. Então a espera por uma requisição precisa suspender a Fiber em que roda (ou rodar o loop, fora de uma Fiber) e voltar com o valor, e o núcleo nunca vê uma promise. O Amp 3 foi reescrito sobre Fibers: `Amp\async()` inicia uma Fiber, `Future::await()` a suspende até o valor chegar, e um `Amp\Cancellation` interrompe a espera. O modelo principal do ReactPHP são promises e callbacks; o `react/async` 4 acrescenta `await()` sobre Fibers, mas o cancelamento e os timeouts continuam na API de promises, e este pacote precisaria de uma camada para adaptá-los.
2. **O Revolt é o denominador comum.** O Amp 3 roda sobre o event loop do Revolt, e o ReactPHP roda sobre ele também, pelo adaptador oficial `revolt/event-loop-adapter-react`. Não existe adaptador no sentido contrário. Uma aplicação ReactPHP roda este pacote no mesmo loop do seu próprio código; um pacote construído sobre o ReactPHP teria deixado de fora as aplicações Amp.
3. **Cancelamento casado com o prazo.** O `Amp\Cancellation` de uma requisição chega à closure do usuário como o seu terceiro parâmetro e segue como está para o cliente do Amp, para o `Amp\delay()`, para um SDK. A sua parte de prazo é um único `Amp\TimeoutCancellation` do tempo que resta no estágio, compartilhado por toda requisição da chamada, então o prazo que o núcleo entrega ao `fetchAll()` é cumprido do jeito que o próprio lote `curl_multi` do núcleo o cumpre: o mesmo instante, o mesmo orçamento mínimo, o mesmo `resolutionTimeout()`, sem nada traduzido no meio.
4. **Peso.** Dois pacotes pequenos, `revolt/event-loop` e `amphp/amp`. O mesmo sobre o ReactPHP seriam `react/event-loop`, `react/promise` e `react/async`, mais o adaptador para compartilhar o loop com aplicações Amp.

## O gancho do núcleo não conhece event loop

O ASpecification 2.6.0 acrescentou duas interfaces em `Antevemus\ASpecification\Resolution`: `IDeferredDataSource`, uma fonte que nomeia o transporte que a busca dentro de um estágio de resolução, e `IStageTransport`, cujo `fetchAll()` recebe toda requisição pendente daquele transporte no estágio. Um estágio busca em três grupos, sempre nesta ordem e um depois do outro: as fontes HTTP num único lote `curl_multi`, depois um `fetchAll()` por transporte, depois as demais fontes. O núcleo chama `fetchAll()` e espera que ele retorne; nunca referencia Revolt, Amp ou Fibers, não depende de nenhum deles e não roda event loop.

Tudo o que diz respeito ao loop vive neste pacote: a `RevoltDataSource` é uma `IDeferredDataSource` e o `RevoltStageTransport` é o seu `IStageTransport`. O gancho, na documentação do núcleo: [Fontes adiadas e transportes de estágio](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/docs/pt-BR/resolution/deferred.md). Como este pacote o usa: [Como funciona](../concepts/how-it-works.md).

## O que um satélite para outro event loop implementa

O mesmo gancho serve a qualquer event loop. Um satélite para outro implementa duas classes, e nada muda no núcleo nem neste pacote:

- **Uma fonte** que implementa `IDeferredDataSource`. `getTransport()` devolve o seu transporte (um transporte pode servir muitas fontes: o núcleo agrupa as requisições de um estágio por instância de transporte). `getCost()` e `getTtl()` funcionam como para qualquer fonte. `fetch()` continua obrigatório: é o caminho síncrono fora de um escopo de resolução (uma folha avaliada por `$spec->evaluate($candidate)` sem um resolvedor), então devolve o valor ou lança, esteja o loop já rodando ou não. Uma fonte que também é `IHttpDataSource` é buscada como HTTP, nunca pelo seu transporte.
- **Um transporte** que implementa `IStageTransport::fetchAll(array $pending, ?float $deadline): array`. `$pending` mapeia cada chave de requisição (`DataRequest::getKey()`) para `[$source, $request]`; a resposta mapeia as mesmas chaves para o valor da requisição ou para o `Throwable` que a fez falhar. O transporte inicia as requisições juntas e retorna quando todas terminaram ou o prazo chegou: o próximo grupo do estágio só começa depois que ele retorna.

As regras da resposta, todas definidas pelo núcleo:

- um `Throwable` na resposta faz falhar só aquela requisição: o erro de avaliação da sua folha, listado por `getFailedRequests()`; qualquer outro valor é um sucesso;
- uma chave que o transporte não responde falha com `DataFetchException::noTransportResult()`; uma chave que ele responde sem ter sido pedida é ignorada;
- um `Throwable` lançado pelo próprio `fetchAll()` faz falhar toda requisição daquele transporte, e só essas: os outros transportes e fontes do estágio são buscados normalmente;
- `$deadline` é o instante monotônico do limite de tempo da resolução, comparável a `CurlMultiTransport::now()`, ou `null` sem limite: o transporte para de esperar nele e responde as requisições não terminadas com `DataFetchException::resolutionTimeout()`; o núcleo não chama um transporte cujo prazo já passou;
- um valor é guardado em cache pelo núcleo com o TTL da sua fonte, e uma falha nunca é guardada, então a próxima resolução pergunta ao transporte de novo; uma requisição respondida pelo cache nunca chega ao transporte;
- `fetchAll()` pode receber uma única requisição: no `sequential()` cada folha chega ao transporte sozinha, quando a avaliação chega a ela.

Para dar as mesmas falhas das próprias fontes do núcleo, mapeie-as com as fábricas da `DataFetchException`, como o `RevoltStageTransport` faz (`<name>` é `$request->source`, o nome com que a fonte foi registrada):

| O que falhou | A resposta | Como o núcleo faz para |
| :--- | :--- | :--- |
| o código do usuário lançou | `DataFetchException::sourceFailed(<name>, $e)`, a original como `getPrevious()` | uma `CallableDataSource` que lança |
| o código do usuário lançou uma `DataPendingException` | essa exceção, como está | uma `AsyncDataSource` cujo resultado não está pronto |
| o próprio limite de tempo da fonte se esgotou | `DataFetchException::timeout(<name>, <seconds>)` | uma `HttpDataSource` que passou do seu próprio limite de tempo |
| o prazo da resolução se esgotou | `DataFetchException::resolutionTimeout(<name>)`; com menos de `CurlMultiTransport::MIN_START_BUDGET` (10 ms) restantes, nada é iniciado e toda requisição é respondida com ela | o lote `curl_multi` do núcleo |

Essas fábricas escrevem apenas o nome da fonte e a classe da exceção original, nunca um valor de parâmetro. Os testes de paridade de [Testes](../quality/tests.md) comparam as respostas deste pacote com as fontes do núcleo; um satélite para outro loop pode reaproveitar a abordagem deles.

## O ReactPHP hoje

Uma aplicação ReactPHP usa este pacote como ele é: com o `revolt/event-loop-adapter-react` instalado, o loop do ReactPHP roda sobre o loop do Revolt, e as Fibers do `RevoltStageTransport` o compartilham com o próprio código da aplicação. Um `antevemus/aspecification-react` dedicado seria um pacote novo do tamanho deste (uma fonte, um transporte e um runner) sobre o mesmo gancho do núcleo, sem tocar no núcleo nem neste pacote. Ele não está planejado e seria construído sob demanda: [Roadmap](../project/roadmap.md).
