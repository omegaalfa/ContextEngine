<div align="center">

# Ω ContextEngine

### RAG tipado e multi-tenant para aplicações PHP 8.4

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![CI](https://github.com/omegaalfa/ContextEngine/actions/workflows/ci.yml/badge.svg)](https://github.com/omegaalfa/ContextEngine/actions/workflows/ci.yml)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-pgvector-4169E1?logo=postgresql&logoColor=white)](https://github.com/pgvector/pgvector)
[![License MIT](https://img.shields.io/badge/license-MIT-22C55E)](LICENSE)

**Documentos → embeddings → busca vetorial → contexto → resposta com fontes**

</div>

ContextEngine é uma biblioteca PHP para construir pipelines de RAG (*Retrieval-Augmented Generation*). Ela prepara documentos, armazena embeddings no PostgreSQL/pgvector, recupera chunks relevantes e entrega contexto a um modelo de linguagem.

É o motor da solução, não uma aplicação completa: não inclui interface web, autenticação, controller HTTP ou CLI de produto.

> [!IMPORTANT]
> O núcleo é funcional, usa versões estáveis das dependências Omegaalfa e possui testes automatizados. O ContextEngine ainda está em desenvolvimento ativo e não deve ser apresentado como solução completa de produção: faltam recursos operacionais como observabilidade integrada, retry de providers e substituição atômica de versões completas de documentos. Avalie-o em uma aplicação real antes de cargas críticas.

## ✨ Recursos principais

| Disponível | Ainda não incluído |
|---|---|
| ✅ Pipeline incremental de ingestão | ⚠️ Loaders estruturados para HTML ou Markdown |
| ✅ Busca vetorial e híbrida com PostgreSQL/pgvector | ⚠️ Embeddings Gemini |
| ✅ RAG com resposta, fontes e abstenção | ⚠️ Reranker cross-encoder local incluído no pacote |
| ✅ Tenant, collection, status e versões | ⚠️ Judge semântico por LLM |
| ✅ `EmbeddingSpace` e fingerprint | ⚠️ Streaming incremental ainda nao incluido em todos os providers |
| ✅ Providers substituíveis por contratos | ⚠️ Interface web, API ou autenticação |
| ✅ Cache PSR-16 opcional por decorators | |
| ✅ Concorrência controlada com Fibers | |
| ✅ Upsert idempotente por espaço vetorial | |
| ✅ Ativação atômica de versões de documento | |
| ✅ Multi-query, RRF, vizinhos e seleção adaptativa | |
| ✅ Reranking opcional: textual e cross-encoder Cohere | |
| ✅ Política de evidência para busca híbrida | |
| ✅ Candidate pools independentes para vetor, lexical, RRF, reranker e contexto | |
| ✅ Diagnósticos de retrieval/RAG | |
| ✅ LLMs OpenAI, Ollama e Gemini | |

## 🧭 Como funciona

**Diagrama executivo — visão conceitual:**

```text
INGESTÃO                                  CONSULTA RAG

Documentos                               Pergunta
    ↓                                        ↓
Loader                                  mesmo EmbeddingProvider
    ↓                                        ↓
Splitter → chunks                       Retriever
    ↓                                        ↓
EmbeddingProvider ───────────────────→ Vector Store
    ↓                                        ↓
Vector Store                            Contexto selecionado
    ↓                                        ↓
PostgreSQL + pgvector                   Language Model
                                             ↓
                                        Resposta + fontes
```

O mesmo `EmbeddingProvider` cria vetores dos chunks e da pergunta. Assim, todos usam um espaço vetorial compatível. Documentos são ingeridos quando adicionados ou atualizados; durante uma pergunta, os arquivos não são lidos novamente.

## 💡 Por que usar o ContextEngine?

Pode ser adequado quando:

- sua aplicação principal já está em PHP moderno;
- você deseja controlar diretamente a composição do pipeline;
- quer usar PostgreSQL/pgvector como store vetorial;
- não quer introduzir um serviço Python apenas para o fluxo RAG;
- precisa de isolamento por tenant e collection;
- quer substituir providers por contratos próprios;
- precisa testar loader, splitter, provider, store e modelo separadamente;
- quer cache opcional sem acoplá-lo ao domínio.

Essa é uma decisão arquitetural, não uma comparação de desempenho com outros frameworks.

## 📦 Requisitos

- PHP `^8.4` com PDO, `pdo_pgsql` e sockets;
- Composer;
- PostgreSQL com extensão pgvector;
- schema provisionado pela aplicação;
- OpenAI ou Ollama para os adapters de embedding incluídos;
- OpenAI para o `LanguageModel` incluído, ou implementação própria;
- Redis somente se a aplicação escolher um cache PSR-16 baseado em Redis.

## ⬇️ Instalação

As dependências do ecossistema usam releases estáveis e são resolvidas normalmente pelo Composer. Quando o ContextEngine estiver publicado no Packagist, a instalação será:

```bash
composer require omegaalfa/context-engine
```

Para desenvolver o próprio repositório:

```bash
git clone https://github.com/omegaalfa/ContextEngine.git
cd ContextEngine
composer install
composer check
```

O `composer.lock` é versionado para tornar CI e desenvolvimento deste repositório reproduzíveis. Aplicações consumidoras resolvem as constraints declaradas no `composer.json` da biblioteca; o lock da biblioteca não controla as versões instaladas nelas.

Consulte [Primeiros passos](docs/getting-started.md) para banco, dimensão, Docker e configuração completa.

Exemplos executáveis:

```bash
php examples/simple-ingestion.php
php examples/simple-search.php "Em quanto tempo posso solicitar um reembolso?"
php examples/simple-rag.php "Em quanto tempo posso solicitar um reembolso?"
php examples/ingest-algorithms-book.php
php examples/llphant-simple-search.php
php examples/llphant-simple-rag.php
php examples/01-vector-search.php
php examples/02-lexical-search.php
php examples/03-multi-query.php
php examples/04-rrf.php
php examples/05-context-expansion.php
php examples/06-hybrid-search.php
php examples/07-diagnostics.php
php examples/08-end-to-end-rag.php
php examples/09-ask.php
php examples/10-stream.php
```

`simple-ingestion.php` usa o Bootstrap tipado e aceita opcionalmente o caminho de outro arquivo como primeiro argumento. O segundo comando faz somente retrieval vetorial e mostra os chunks encontrados; a LLM é chamada apenas pelo terceiro comando.

Os exemplos numerados `01` a `08` sao independentes, rodam sem banco externo e imprimem cada etapa do pipeline com tempos, documentos encontrados e chunks selecionados. Veja o guia em [Playbook de exemplos](docs/examples-retrieval-playbook.md).

## ⚡ Composição pronta e tipada

Para o cenário padrão com **Ollama + PostgreSQL/pgvector**, o `Bootstrap` constrói diretamente conexão, store vetorial, provider de embeddings, executor concorrente, retriever, ingestão e RAG. O retorno é um `ContextEngineContext` fortemente tipado: não existe container oculto, chamada a `get()` ou Service Locator na API.

```php
use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

$context = Bootstrap::create(
    config: ContextEngineConfigFactory::fromEnvironment(),
    languageModelFactory: static fn (AsyncHttpClient $http) => new OllamaLanguageModel(
        model: 'qwen3:8b',
        client: $http->readTimeout(300)->totalTimeout(300),
    ),
);

$answer = $context->rag->ask(
    new Question('Qual é o prazo para reembolso?', 'tenant-42'),
);
```

A factory do modelo recebe o mesmo `AsyncHttpClient` usado pelo provider de embeddings. Por isso, embeddings e LLM compartilham exatamente um `FiberEventLoop`, sem expor loop, HTTP client ou infraestrutura na API pública. O contexto também oferece `$context->retriever`, `$context->ingestion`, `$context->embeddings` e `$context->store` com autocomplete e tipos explícitos.

| Operação | Embeddings | Vector store | Language model |
|---|---:|---:|---:|
| `$context->ingestion->ingest(...)` | ✅ | ✅ | não é chamada |
| `$context->retriever->retrieve(...)` | ✅ | ✅ | não é chamada |
| `$context->rag->ask(...)` | ✅ | ✅ | ✅ |

> [!IMPORTANT]
> A `languageModelFactory` é executada na criação do contexto, então sua configuração é validada imediatamente. Entretanto, a requisição HTTP ao Gemini, Ollama ou OpenAI só ocorre em `$context->rag->ask()`. Veja o [guia visual do Bootstrap](docs/bootstrap.md) para o fluxo completo e exemplos de troca de provider.

Veja o exemplo executável em [`examples/simple-rag.php`](examples/simple-rag.php) e a explicação completa em [Bootstrap tipado](docs/bootstrap.md).

## 📥 Ingestão mínima

**Código copiável; pressupõe `$embeddingProvider`, `$vectorStore` e `$batchExecutor` já configurados. Se o provider usa `AsyncHttpClient`, o cliente e o executor devem compartilhar o mesmo event loop:**

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;

$pipeline = new IngestionPipeline(
    splitter: new RecursiveTextSplitter(chunkSize: 1_000, overlap: 150),
    embeddings: $embeddingProvider,
    store: $vectorStore,
    executor: $batchExecutor,
    batchSize: 32,
);

$report = $pipeline->ingest(
    new TextFileLoader('/data/manual.txt', 'tenant-42'),
);
```

O loader pronto usa collection `default`, status `active` e cria um documento por bloco de texto separado por linha vazia.

## 💬 Pergunta mínima

**Código copiável; pressupõe os mesmos embeddings/store e um `$languageModel`:**

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

$rag = new RagPipeline(
    retriever: new Retriever(
        embeddings: $embeddingProvider,
        store: $vectorStore,
        collection: 'default',
    ),
    prompts: new ContextPromptBuilder(),
    model: $languageModel,
);

$answer = $rag->ask('Qual é o prazo de reembolso?', 'tenant-42');

echo $answer->content;
foreach ($answer->sources as $source) {
    echo PHP_EOL . $source->chunk->content;
}
```

## 🔁 ask() vs stream()

Por padrao, a leitura de resposta usa `ask(...)` e retorna a resposta final completa (buffered).

Para leitura incremental, a aplicacao deve chamar `stream(...)` explicitamente:

```php
$full = $context->rag->ask(new Question('Explique a politica.', 'tenant-42'));

foreach ($context->rag->stream(new Question('Explique a politica.', 'tenant-42')) as $delta) {
    if ($delta->final) {
        break;
    }
    echo $delta->content;
}
```

No estado atual do pacote, `OpenAILanguageModel` oferece streaming incremental real; outros providers podem permanecer apenas no fluxo buffered.

## 🧩 Infraestrutura incluída

- **Embeddings:** `OpenAIEmbeddingProvider` e `OllamaEmbeddingProvider`; o schema fornecido está preparado para `bge-m3`/1024 via Ollama.
- **LLM:** `OpenAILanguageModel`, `OllamaLanguageModel` e `GeminiLanguageModel` para resposta completa; `OpenAILanguageModel` tambem suporta streaming incremental real.
- **Store:** `PgVectorStore` via `omegaalfa/query-builder`.
- **Cache:** `CachedEmbeddingProvider` e `CachedLanguageModel`, ambos PSR-16.
- **Concorrência:** `FiberBatchEmbeddingExecutor`, sem `Future` na API pública. Providers baseados em `AsyncHttpClient` compartilham com o executor uma única instância de `FiberEventLoop` criada no bootstrap.
- **Streaming:** contrato independente; streaming incremental real disponivel no provider OpenAI.
- **Contexto vazio:** o RAG não chama o LLM sem fontes; retorna a mensagem configurada pela política de ausência de evidência.
- **PDF textual:** `PdfDocumentLoader` com janelas configuráveis e `PopplerPdfTextExtractor` opcional via binário `pdftotext`.

O pacote inclui `GeminiLanguageModel` para respostas completas. Embeddings Gemini e outros recursos do fornecedor continuam extensíveis pelos contratos públicos.

## 🚧 Limitações resumidas

### Escopo deliberado

- biblioteca sem interface web/API/autenticação;
- schema gerenciado pela aplicação;
- PostgreSQL/pgvector é o store concreto incluído.

### Ainda não implementado

- streaming incremental para providers que ainda não suportam essa capacidade;
- cross-encoder local executado dentro da aplicação; o pacote inclui o adapter remoto `CohereReranker`;
- OCR para PDFs escaneados e análise automática de capítulos;
- loaders estruturados para HTML, Markdown e formatos de escritório.

### Infraestrutura

- `vector(n)` possui dimensão física fixa;
- provider, modelo, dimensão, revisão e schema precisam ser compatíveis;
- serviços externos exigem rede/credenciais; Ollama exige serviço/modelo local.

Veja [Limitações e escopo](docs/limitations.md) para os impactos práticos.

## 📚 Documentação

| Objetivo | Documento |
|---|---|
| Aprender RAG do zero | [Primeiros passos](docs/getting-started.md) |
| Entender módulos e dependências | [Arquitetura](docs/architecture.md) |
| Consultar termos centrais | [Conceitos](docs/core-concepts.md) |
| Descobrir qual classe ou método usar | [Mapa completo da API](docs/public-api-map.md) |
| Configurar fornecedores | [Providers](docs/providers.md) |
| Ingerir livros e PDFs textuais | [Ingestão de PDF](docs/pdf-ingestion.md) |
| Entender multi-query, RRF e vizinhos | [Pipeline de retrieval](docs/retrieval-pipeline.md) |
| Entender por que uma ou várias fontes chegam à IA | [Retrieval para iniciantes](docs/retrieval-for-beginners.md) |
| Comparar com LLPhant usando Ollama | [ContextEngine e LLPhant](docs/llphant-comparison.md) |
| Auditar o protocolo entregue ao LLM | [Protocolo de prompt v3](docs/prompt-protocol.md) |
| Reduzir fontes adaptativamente | [Adaptive context](docs/adaptive-context-selection.md) |
| Comparar com Neuron AI | [ContextEngine x Neuron](docs/neuron-comparison.md) |
| Configurar decorators | [Cache](docs/caching.md) |
| Criar adapters próprios | [Extensão](docs/extension-guide.md) |
| Diagnosticar problemas | [Troubleshooting](docs/troubleshooting.md) |
| Medir retrieval e qualidade factual | [Avaliação reproduzível](docs/evaluation.md) |
| Avaliar fronteiras atuais | [Limitações](docs/limitations.md) |
| Navegar por todos os guias | [Índice completo](docs/index.md) |

## ✅ Qualidade

```bash
composer validate --strict
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
```

Integrações pgvector e Redis são opt-in. Consulte [Docker e integração](docs/docker-integration.md).

```bash
./context-engine.sh          # abre o menu visual interativo
```

## 📄 Licença

[MIT](LICENSE)
