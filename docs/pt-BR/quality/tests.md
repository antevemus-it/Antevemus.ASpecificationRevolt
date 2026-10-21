# 🧪 Testes

A suíte roda no PHPUnit 11 sob o PHP 8.2, 8.3 e 8.4 (a matriz da CI), sem extensão do PHP exigida pelo pacote (a `HttpDataSource` do núcleo, a referência dos testes HTTP, usa o `ext-curl`):

```bash
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
```

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.13
Configuration: phpunit.xml.dist

................................................................. 65 / 85 ( 76%)
....................                                              85 / 85 (100%)

Time: 00:20.866, Memory: 8.00 MB

OK (85 tests, 5042 assertions)
```

- **85 testes e 5042 asserções**, nenhum pulado, em três suítes: `tests/Unit` (as duas classes, o runner, a paridade com a fonte callable, a documentação), `tests/Property` (árvores aleatórias) e `tests/Http` (a paridade HTTP e a prova de ganho, grupo `http`). A mesma suíte passou no PHP 8.2.34 (um contêiner, com as dependências resolvidas para a 8.2). O relato arquivo por arquivo está em `tests/README.md`.
- **O transporte** (`RevoltStageTransportTest`): respostas por chave, na ordem recebida; uma exceção da closure como `sourceFailed()` que falha só a sua requisição, com o nome da fonte e nunca o valor de um parâmetro; o próprio limite de tempo da fonte como `timeout()`, a closure cancelada pelo seu `Cancellation`, e uma closure que o ignora falhando mesmo assim no seu limite; o prazo da resolução (16 closures de 1 s, um prazo de 50 ms: toda requisição falha com `resolutionTimeout()`, a chamada retorna em cerca de 50 ms, as oito closures que começaram são canceladas, as oito que esperavam uma vaga nunca rodam, e nenhum timer fica no event loop); um prazo abaixo do orçamento mínimo do núcleo não inicia nada; o prazo e o limite da fonte distinguidos; um valor pronto antes do prazo mantido qualquer que seja a ordem das chaves; o `maxConcurrency` respeitado (o pico de closures rodando ao mesmo tempo é 1, 3, 8 e 16 para esses limites; 16 fontes de 100 ms levam cerca de 200 ms com 8); requisições esperando vaga não passam do prazo; uma `DataPendingException` repassada como está (`getPendingRequests()`, `isPending()`); outra fonte adiada buscada pelo seu próprio `fetch()`; fora de uma Fiber a espera roda o loop, dentro de uma (`DataResolver::evaluate()` num `Amp\async()`) suspende só aquela Fiber enquanto outra roda; duas resoluções dividindo um transporte limitadas juntas; um estágio misturando fontes Revolt e callable na ordem do núcleo.
- **A fonte** (`RevoltDataSourceTest`): as verificações de argumentos, os getters e o transporte compartilhado, os argumentos da closure pela sua aridade (um, dois, três parâmetros, variádica), um `Future` devolvido e aguardado (com a sua falha e o seu limite de tempo), `fetch()` lançando as falhas mapeadas, `fetch()` pelo próprio transporte da fonte, uma folha fora de um escopo de resolução usando `fetch()`, `fetch()` dentro de uma Fiber, a política sequencial e o cache.
- **O runner** (`RevoltRunnerTest`, copiado com a classe do `antevemus/alinq-revolt`): o valor de uma tarefa, a sua exceção, o limite de vagas com os chamadores esperando na ordem de chegada, o limite de tempo, o cancelamento recebido, `cancel()`.
- **Paridade com as fontes do núcleo** (`ParityTest`, `HttpParityTest`): as mesmas 10 árvores sobre a mesma bateria (valores, uma falha, um limite de tempo, um dado ausente, uma fonte desconhecida, um candidato sem o parâmetro ou com ele nulo), 5 candidatos, sob `concurrent()`, `tiered()` e `sequential()`, por `CallableDataSource` e por `RevoltDataSource`: o mesmo veredito, as mesmas marcas de erro de avaliação e de pendência, as mesmas marcas `evaluation_error`, motivos, códigos, classe da exceção e nome da fonte, requisições falhas, conhecidas e puladas, contagens de estágios e de buscas (150 comparações). Contra o servidor Amp local, 8 árvores por `HttpDataSource`, por `RevoltDataSource` com o cliente do Amp e por `CallableDataSource` com um cliente bloqueante: uma resposta, um status 503 (uma falha nos três) e o limite de tempo da fonte (HTTP e Revolt dão a mesma mensagem), sob `concurrent()` e `sequential()` (52 comparações).
- **Propriedades** (`ResolutionPropertyTest`, 300 casos semeados, semente fixa): árvores aleatórias de folhas `Spec::external()` sobre 3 a 6 fontes aleatórias (uma em seis falhando), cada uma uma `RevoltDataSource` num de 1 a 3 transportes ou uma `CallableDataSource`, com custos aleatórios: o mesmo veredito pelas fontes Revolt e por fontes callable com as mesmas respostas, sob as três políticas e entre elas; `getFetchCount()` igual ao número de requisições distintas buscadas; o `fetchAll()` de cada transporte chamado uma vez no `concurrent()` quando ele tem requisições, e no `tiered()` no máximo uma vez por estágio (um custo por chamada, nunca duas chamadas para o mesmo custo, não mais chamadas que estágios). Contado por um `IStageTransport` decorador (`RevoltStageTransport` é final). Medido com a semente: 63 casos com dois transportes ou mais num estágio, 59 resoluções tiered que pularam requisições, 174 erros de avaliação, 44 transportes chamados em dois estágios tiered.
- **A documentação** (`DocumentationBlocksTest`, `DocumentationTreeTest`): os 7 blocos PHP de cada idioma e o Início Rápido de cada README rodam como escritos, com toda resposta dos seus comentários conferida; os dois idiomas têm as mesmas páginas, navegação e código, token a token; todo link resolve; nada interno vaza.

## Prova de ganho

`tests/Http/ProofOfGainTest.php` sobe um servidor HTTP não bloqueante do Amp numa porta livre de 127.0.0.1 num processo filho (`tests/Support/latency-server.php`, adaptado do `antevemus/alinq-revolt`; `GET /item?ms=200` responde depois de 200 ms sem bloquear) e roda uma resolução de N folhas de dados externos, cada uma uma requisição distinta, num único estágio do `DataResolver`. Ele afirma, com limites generosos e os tempos medidos em toda mensagem de falha: `CallableDataSource` com um cliente bloqueante acima de 700 ms para 4 fontes e de 2 500 ms para 16; `RevoltDataSource` com o cliente HTTP do Amp abaixo de 600 ms para 16 fontes com `maxConcurrency` 16, abaixo de 800 ms com o padrão 8, abaixo de 500 ms para 4 e de 450 ms para 1, nunca abaixo das rodadas de que precisa; a `HttpDataSource` do núcleo abaixo de 600 ms para 16; Revolt com 16 pelo menos três vezes mais rápido que as callables; o mesmo cliente bloqueante dentro de uma `RevoltDataSource` acima de 2 500 ms; e 8 closures que só calculam (cerca de 25 ms cada, calibradas) levando pelo menos 70% do tempo sequencial como `RevoltDataSource`. Quando o servidor não consegue subir, os testes são pulados com o motivo, nunca dados como aprovados.

A tabela, medida com `XDEBUG_MODE=off php tests/Support/timing-table.php 3` (mediana de 3 rodadas por célula, PHP 8.4.13, um cliente do Amp novo por rodada):

| Fontes × 200 ms | CallableDataSource, bloqueante | RevoltDataSource (8), cliente do Amp | RevoltDataSource (16), cliente do Amp | HttpDataSource (curl_multi) | RevoltDataSource (16), bloqueante |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 203 ms | 202 ms | 203 ms | 201 ms | 202 ms |
| 4 | 809 ms | 208 ms | 211 ms | 205 ms | 809 ms |
| 16 | 3 227 ms | 428 ms | 215 ms | 210 ms | 3 244 ms |

| 8 closures de cerca de 25 ms de CPU | CallableDataSource | RevoltDataSource (16) |
| ---: | ---: | ---: |
| 8 | 278 ms | 268 ms |

- **Por que cada número:** uma chamada bloqueante segura a única thread, então n fontes levam n × 200 ms qualquer que seja o transporte (a primeira e a última colunas). Com o cliente do Amp, `maxConcurrency` requisições esperam juntas: 16 fontes levam ⌈16 / limite⌉ rodadas de 200 ms (428 ms com 8, duas rodadas; 215 ms com 16, uma); 4 fontes cabem numa rodada com 8 e com 16. O lote `curl_multi` do núcleo sobrepõe as mesmas chamadas sem event loop nenhum (210 ms). Os poucos milissegundos acima de cada múltiplo de 200 são as próprias requisições pelo loopback e a resolução em volta delas.
- **Closures só de CPU:** uma Fiber que calcula nunca devolve a thread, então 8 closures de cerca de 25 ms levam cerca de 8 × 25 ms de um jeito ou de outro. Esta linha varia de rodada para rodada na máquina da medição (WSL2, uma CPU compartilhada): duas rodadas completas do mesmo comando deram 254 e 398 ms, depois 278 e 268 ms; a tabela mostra a segunda. Em nenhuma delas a coluna Revolt fica significativamente abaixo da sequencial, que é o ponto.

## Teste de mutação

Infection sobre todo o `src/` (`infection.json5`, o workflow de Qualidade), com o grupo `http` de fora porque ele mede tempo de relógio:

```bash
XDEBUG_MODE=off vendor/bin/infection --threads=max --only-covering-test-cases \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=$PWD/src"
```

Medido em 2026-10-20 (Infection 0.35.8, PHP 8.4.13 com pcov): **121 mutantes; 115 mortos pelos testes, 2 erros (detectados), 4 escapados, todos equivalentes; MSI 96,69%** (MSI do código coberto 96,69%, cobertura de mutação 100%). Por classe: `RevoltDataSource` 36 de 36 mortos; `RevoltStageTransport` 37 de 38; `RevoltRunner` 42 mortos, 2 erros e 3 escapados de 47. Os erros são dois mutantes do runner que quebram a passagem de uma vaga (o `suspend()` removido esgota a memória; o teste invertido da lista de espera chama `resume()` sobre nada). Uma primeira rodada teve mais um mutante escapado: o `ignore()` do future de cada requisição, redundante porque todo future é aguardado em seguida (aguardar o marca como tratado), então a chamada foi removida. Os 4 mutantes escapados:

- no `RevoltRunner` (os mesmos três do `antevemus/alinq-revolt`): remover o `$everything->cancel()` em `cancel()`, cujo `Amp\DeferredCancellation` é liberado logo depois e cancela no seu destrutor; remover o `unsubscribe()` do cancelamento combinado quando uma tarefa termina, um cancelamento liberado com a tarefa; transformar o `finally` de uma tarefa em código comum depois do seu `try`/`catch`, cujo `catch (Throwable)` já recebe toda exceção;
- no `RevoltStageTransport`: `<` virando `<=` na comparação do tempo restante com o orçamento mínimo do núcleo, dois valores de um relógio monotônico que na prática nunca são iguais (a verificação do próprio núcleo é o mesmo `<`).

`minMsi` e `minCoveredMsi` são 95 no `infection.json5`, um ponto inteiro abaixo da pontuação medida.
