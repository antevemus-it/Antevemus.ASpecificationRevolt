# Antevemus ASpecification Revolt

A documentação oficial do pacote, uma página por tópico. As mesmas páginas existem em inglês, em `docs/en`, e todo bloco PHP destas páginas é executado pela suíte de testes com as respostas escritas nos seus comentários.

> **O satélite Revolt/Fibers do [ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) para PHP 8.2+**  
> `RevoltDataSource` e `RevoltStageTransport` buscam os dados externos não HTTP de um estágio de resolução de forma concorrente sob o event loop do Revolt (Fibers, futures do Amp), pelo gancho `IDeferredDataSource`/`IStageTransport` do núcleo: um limite de concorrência, um limite de tempo por fonte e o prazo da resolução, com as mesmas falhas das próprias fontes do núcleo. Para dados atrás de clientes não bloqueantes (Amp, SDKs assíncronos).

## Mapa da Documentação

### Primeiros passos

- [Instalação e início rápido](getting-started/index.md): Composer, os requisitos e o Início Rápido.

### Conceitos

- [Como funciona](concepts/how-it-works.md): Os três grupos de um estágio de resolução, o transporte e o runner, o event loop, e por que Fibers só ganham tempo em I/O não bloqueante.

### Referência da API

- [RevoltDataSource e o transporte](api/revolt-data-source.md): O construtor, a closure, custo e TTL, o limite de tempo, as falhas e as suas exceções, o `maxConcurrency`, o transporte compartilhado e o uso dentro de uma Fiber.

### Internos

- [Arquitetura](internals/architecture.md): Por que Revolt e Amp e não ReactPHP, o gancho do núcleo que não conhece event loop, e o que um satélite para outro event loop implementa.

### Qualidade

- [Testes](quality/tests.md): O relatório do PHPUnit, a paridade com as fontes do núcleo, a tabela de tempos medida e o teste de mutação.

### Projeto

- [Roadmap](project/roadmap.md): A versão entregue e o que vem depois; a visão por marcos está no ROADMAP.md.

## Onde Está Cada Coisa

| O quê | Onde |
| :--- | :--- |
| Visão geral, badges e o Início Rápido | [README.pt-BR.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/README.pt-BR.md) |
| O que mudou em cada versão | [CHANGELOG.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/CHANGELOG.md) |
| Marcos entregues e planejados | [ROADMAP.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/ROADMAP.md) |
| Procedência e licenças de terceiros | [NOTICE.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/NOTICE.md), [THIRD_PARTY_NOTICES.md](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/THIRD_PARTY_NOTICES.md) |
| Licença | [LICENSE](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/LICENSE) |
| O núcleo e a sua resolução de dados externos | [Antevemus.ASpecification](https://github.com/antevemus-it/Antevemus.ASpecification) |
