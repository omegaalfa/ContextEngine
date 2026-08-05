# High-Level API do ContextEngine

## Introdução

A High-Level API do ContextEngine oferece um ponto de entrada simples para quem quer usar a biblioteca sem conhecer detalhes de infraestrutura, providers, pipelines ou stores.

Ela existe para reduzir a fricção de uso e manter o modelo arquitetural atual intacto: a nova camada apenas orquestra os componentes já existentes.

## Quando usar

Use a High-Level API quando:

- você quer começar rapidamente;
- não deseja montar Bootstrap manualmente;
- prefere uma configuração fluente e legível;
- quer manter compatibilidade com a arquitetura atual.

## Primeiros passos

```php
<?php

use Omegaalfa\ContextEngine\HighLevel\ContextEngine;

$engine = ContextEngine::create()
    ->tenant('empresa')
    ->collection('algorithms')
    ->ollama(
        baseUrl: 'http://127.0.0.1:11434',
        embeddingModel: 'bge-m3',
        languageModel: 'llama3.1:8b',
    )
    ->build();
```

## Configuração

### Escopo

```php
$engine = ContextEngine::create()
    ->tenant('empresa')
    ->collection('contracts')
    ->status('active')
    ->build();
```

### Provider Ollama

```php
$engine = ContextEngine::create()
    ->ollama(
        baseUrl: 'http://127.0.0.1:11434',
        embeddingModel: 'bge-m3',
        languageModel: 'llama3.1:8b',
    )
    ->build();
```

### Provider OpenAI

```php
$engine = ContextEngine::create()
    ->openAi(
        apiKey: 'sk-...',
        model: 'gpt-4.1-mini',
    )
    ->build();
```

## Ingestão

```php
$engine = ContextEngine::create()
    ->ingestion(
        batchSize: 32,
        concurrency: 4,
        chunkSize: 10000,
        chunkOverlap: 0,
    )
    ->build();
```

## Busca

```php
$engine = ContextEngine::create()
    ->retrieval(
        heuristicQueryPlanning: true,
        retrievalLimit: 3,
        fusedLimit: 1,
        contextChunkLimit: 1,
        maximumDistance: 0.60,
    )
    ->build();
```

## Modos de resposta

A API de alto nivel possui dois modos de leitura de resposta:

- `ask(...)`: modo padrao, retorna a resposta final completa (buffered).
- `stream(...)`: modo incremental, retorna deltas conforme chegam do provider.

Exemplo rapido:

```php
$engine = ContextEngine::create()
    ->openAi(apiKey: 'sk-...', model: 'text-embedding-3-small')
    ->build();

$full = $engine->ask('How long is the refund window?', 'acme-support');

foreach ($engine->stream('How long is the refund window?', 'acme-support') as $delta) {
    if ($delta->final) {
        break;
    }
    echo $delta->content;
}
```

Observacao: `ask(...)` continua sendo o padrao. `stream(...)` e opt-in e depende do provider implementar streaming incremental real.

## Configuração via ambiente

```php
$engine = ContextEngine::fromEnvironment()->build();
```

## Configuração híbrida

```php
$engine = ContextEngine::fromEnvironment()
    ->tenant('empresa-b')
    ->collection('contracts')
    ->build();
```

## Arquitetura

A High-Level API é uma camada de conveniência sobre a infraestrutura já existente:

- a configuração é traduzida para o objeto existente de configuração;
- o bootstrap continua sendo o ponto de composição real;
- o ContextEngineContext continua sendo o objeto principal retornado.

## Boas práticas

- prefira `fromEnvironment()` para ambientes configurados;
- use overrides explícitos para cenários específicos;
- mantenha `tenant`, `collection` e `status` explícitos;
- ajuste `batchSize` e `chunkSize` conforme o tamanho médio dos documentos.
