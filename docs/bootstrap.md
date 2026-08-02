# ⚡ Bootstrap tipado

> **Uma configuração, um event loop e um contexto com autocomplete.**
>
> O `Bootstrap` é o ponto de composição pronto do ContextEngine para **Ollama embeddings + PostgreSQL/pgvector**, com liberdade para escolher Gemini, Ollama, OpenAI ou outro `LanguageModel` para gerar a resposta final.

---

## 🧭 Visão rápida

Sem o Bootstrap, a aplicação precisa construir conexão, QueryBuilder, vector store, event loop, cliente HTTP, embeddings, executor, splitter, retriever, pipeline de ingestão, prompt builder e modelo de linguagem. O Bootstrap centraliza essa montagem e devolve somente os serviços públicos que a aplicação realmente utiliza.

```text
ContextEngineConfig                         languageModelFactory
        │                                            │
        │ banco, embeddings, batching, retrieval     │ LLM escolhida pela aplicação
        └──────────────────────┬─────────────────────┘
                               ▼
                      Bootstrap::create()
                               │
                  composição direta e tipada
                               │
                               ▼
                    ContextEngineContext
            ┌──────────────┬──────────────┬──────────────┐
            │              │              │              │
        ingestion      retriever          rag       embeddings/store
```

Não existe container escondido, `get()`, Service Locator ou `Future` na API pública.

---

## 📦 O que é criado

| Componente | Responsabilidade | Quando trabalha |
|---|---|---|
| `PgVectorStore` | Persiste e pesquisa vetores no PostgreSQL | Ingestão e retrieval |
| `OllamaEmbeddingProvider` | Transforma chunks e perguntas em embeddings | Ingestão e retrieval |
| `FiberBatchEmbeddingExecutor` | Limita a concorrência dos lotes de embedding | Ingestão |
| `RecursiveTextSplitter` | Divide documentos incrementalmente em chunks | Ingestão |
| `Retriever` | Gera o embedding da pergunta e busca contexto | RAG/retrieval |
| `ContextPromptBuilder` | Monta mensagens seguras com contexto e fontes | RAG |
| `LanguageModel` | Produz a resposta textual final | Somente RAG |
| `IngestionPipeline` | Coordena splitter, embeddings e persistência | Ingestão |
| `RagPipeline` | Coordena retrieval, prompt e LLM | Pergunta RAG |

O resultado é um objeto fortemente tipado:

```php
$context->ingestion;  // IngestionPipeline
$context->retriever;  // Retriever
$context->rag;        // RagPipeline
$context->embeddings; // EmbeddingProvider
$context->store;      // VectorStore
```

---

## 🧩 Por que existe `languageModelFactory`?

### O que é

`languageModelFactory` é uma função criada pela aplicação. Ela recebe o `AsyncHttpClient` que o Bootstrap já preparou e deve devolver qualquer objeto que implemente `LanguageModel`.

```php
languageModelFactory: static fn (AsyncHttpClient $http): GeminiLanguageModel =>
    new GeminiLanguageModel(...)
```

### Para que serve

A factory resolve duas necessidades ao mesmo tempo:

1. mantém a escolha do fornecedor fora do núcleo — Gemini, Ollama e OpenAI continuam intercambiáveis;
2. garante que o provider receba o mesmo cliente HTTP e, consequentemente, o mesmo `FiberEventLoop` criado pelo Bootstrap.

### Como o ContextEngine utiliza

O Bootstrap chama a factory uma vez durante a criação do contexto. O objeto retornado é injetado no `RagPipeline`. Construir o modelo não significa enviar uma pergunta ao fornecedor: a chamada HTTP só acontece quando `rag->ask()` chega à etapa de geração da resposta.

> [!IMPORTANT]
> A factory é executada durante `Bootstrap::create()`. Por isso, API key, modelo e URL são validados imediatamente, mesmo em um processo que depois use apenas `$context->ingestion`. Isso produz erros de configuração previsíveis, mas significa que o contexto completo precisa de uma configuração válida de LLM.

---

## ⏱️ Quando cada provider é chamado?

### Ingestão

```text
Bootstrap::create()
      │ constrói EmbeddingProvider e LanguageModel
      ▼
TextFileLoader
      ▼
$context->ingestion->ingest(...)
      ▼
Splitter → Ollama embeddings → pgvector

LanguageModel: construído ✅ | chamada HTTP ❌
```

Durante a ingestão, o ContextEngine chama o provider de **embeddings**, não Gemini/Ollama/OpenAI de resposta. A LLM fica pronta dentro do contexto, mas não participa do processamento.

### Pergunta RAG

```text
$context->rag->ask(...)
      ▼
Ollama embedding da pergunta
      ▼
Busca no pgvector
      ▼
ContextPromptBuilder
      ▼
Gemini / Ollama / OpenAI
      ▼
Answer + fontes

LanguageModel: construído ✅ | chamada HTTP ✅
```

Essa separação explica por que o mesmo contexto pode ingerir documentos e responder perguntas, sem chamar a LLM durante a ingestão.

---

## 💎 Exemplo completo com Gemini

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$config = ContextEngineConfigFactory::fromEnvironment();

$context = Bootstrap::create(
    config: $config,
    languageModelFactory: static fn (
        AsyncHttpClient $http,
    ): GeminiLanguageModel => new GeminiLanguageModel(
        apiKey: EnvLoader::get('CONTEXT_ENGINE_GEMINI_API_KEY'),
        model: EnvLoader::get('CONTEXT_ENGINE_GEMINI_MODEL'),
        client: $http,
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_GEMINI_URL'),
    ),
);
```

Imports permitem usar `new GeminiLanguageModel(...)` diretamente. Evite combinar o import com o nome totalmente qualificado `new \Omegaalfa\...\GeminiLanguageModel(...)`; funciona, mas adiciona ruído sem trazer segurança adicional.

Configuração correspondente:

```dotenv
CONTEXT_ENGINE_GEMINI_API_KEY=sua-chave
CONTEXT_ENGINE_GEMINI_MODEL=gemini-3.6-flash
CONTEXT_ENGINE_GEMINI_URL=https://generativelanguage.googleapis.com/v1beta
```

> [!WARNING]
> Nomes de modelos remotos têm ciclo de vida próprio. Um HTTP `404` pode significar que o modelo deixou de ser oferecido para sua conta. Em agosto de 2026, `gemini-3.6-flash` é a opção estável usada nos exemplos. Confirme sempre o catálogo oficial antes de produção.

---

## 📥 Usando o contexto para ingestão

Depois da composição, o fluxo de ingestão fica pequeno:

```php
use Omegaalfa\ContextEngine\Loader\TextFileLoader;

$loader = new TextFileLoader(
    path: __DIR__ . '/documents/politica-reembolso.txt',
    tenantId: 'empresa-exemplo',
);

$report = $context->ingestion->ingest($loader);

printf(
    "Persistidos: %d chunks em %d lotes\n",
    $report->chunksPersisted,
    $report->batchesPersisted,
);
```

O que acontece de verdade:

1. o loader lê o arquivo incrementalmente;
2. o splitter produz chunks;
3. o executor envia lotes ao `OllamaEmbeddingProvider` com concorrência limitada;
4. cada resultado é validado;
5. o `PgVectorStore` persiste serialmente;
6. versões completas são ativadas;
7. `IngestionReport` descreve o resultado.

Gemini não recebe nenhuma requisição nessa sequência.

---

## 💬 Usando o mesmo contexto para RAG

```php
use Omegaalfa\ContextEngine\Rag\Question;

$answer = $context->rag->ask(
    new Question(
        text: 'Em quanto tempo posso solicitar um reembolso?',
        tenantId: 'empresa-exemplo',
    ),
);

echo $answer->content;

foreach ($answer->sources as $source) {
    echo $source->chunk->documentId . PHP_EOL;
}
```

Aqui o Gemini é chamado depois que o Retriever encontra os chunks relevantes e o `ContextPromptBuilder` monta as mensagens.

---

## 🔌 Trocando apenas a LLM

A infraestrutura de embeddings e pgvector não muda quando a LLM muda.

| Escolha | Factory | Precisa de credencial remota? | Chamada durante ingestão? |
|---|---|---:|---:|
| Gemini | `GeminiLanguageModel` | Sim | Não |
| OpenAI | `OpenAILanguageModel` | Sim | Não |
| Ollama local | `OllamaLanguageModel` | Não | Não |
| Adapter próprio | qualquer `LanguageModel` | Depende | Não |

### Ollama

```php
languageModelFactory: static fn (AsyncHttpClient $http): OllamaLanguageModel =>
    new OllamaLanguageModel(
        model: 'qwen3:8b',
        client: $http->readTimeout(300)->totalTimeout(300),
        baseUrl: 'http://127.0.0.1:11434',
    ),
```

### OpenAI

```php
languageModelFactory: static fn (AsyncHttpClient $http): OpenAILanguageModel =>
    new OpenAILanguageModel(
        apiKey: (string) getenv('CONTEXT_ENGINE_OPENAI_API_KEY'),
        model: 'gpt-4.1-mini',
        client: $http,
    ),
```

Nenhuma dessas classes implementa streaming incremental. Os adapters atuais recebem a resposta completa e implementam `CacheableLanguageModel`.

---

## 🧵 Um único `FiberEventLoop`

```text
                         FiberEventLoop (1 instância)
                              │
                    AsyncHttpClient compartilhado
                       ┌──────┴────────┐
                       │               │
           OllamaEmbeddingProvider   LanguageModel
                       │               │
                       └──────┬────────┘
                              │
                 FiberBatchEmbeddingExecutor
```

O loop é criado internamente e não aparece em `ContextEngineContext`. A aplicação recebe APIs síncronas e tipadas; `Future` permanece restrito à infraestrutura.

---

## ⚙️ Configuração e precedência

`ContextEngineConfigFactory::fromEnvironment()` somente lê o ambiente. Ela não procura `.env` por conta própria.

```text
Processo / Docker / CI / secret manager
                  ↓ tem precedência
            EnvLoader::load(.env)
                  ↓ completa ausências
 ContextEngineConfigFactory::fromEnvironment()
```

Tenant não pertence à configuração global. Ele vem de cada `Document`, `TextFileLoader` ou `Question`, normalmente depois da autenticação da requisição.

---

## ✅ Qual API usar?

| Objetivo | Chamada |
|---|---|
| Ingerir arquivo | `$context->ingestion->ingest($loader)` |
| Buscar chunks sem LLM | `$context->retriever->retrieve($question)` |
| Responder com RAG | `$context->rag->ask($question)` |
| Acessar embeddings diretamente | `$context->embeddings->embed(...)` |
| Operações avançadas no store | `$context->store->search(...)` |

Exemplos executáveis:

```bash
php examples/simple-ingestion.php
php examples/simple-search.php "Em quanto tempo posso solicitar um reembolso?"
php examples/simple-rag.php "Em quanto tempo posso solicitar um reembolso?"
```

---

## 🚧 Limites intencionais

- A composição pronta escolhe Ollama para embeddings e pgvector para persistência.
- A LLM é intercambiável pelo contrato `LanguageModel`.
- O contexto completo valida uma LLM mesmo quando o processo usa somente ingestão.
- Aplicações que não desejam construir o grafo completo podem compor diretamente apenas os contratos necessários.
- O contexto não expõe event loop, cliente HTTP, conexão PDO ou configuração com senha.
- Não existe streaming simulado: providers buffered não implementam `StreamingLanguageModel`.
