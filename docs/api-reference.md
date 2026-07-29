# ✦ Referência da API pública

Esta página descreve a superfície pública de `Omegaalfa\\ContextEngine`. Tipos `readonly` são imutáveis e argumentos inválidos geram exceções imediatamente.

> Classes em `Infrastructure`, `Provider` e `VectorStore` são adaptadores públicos, não domínio. `Future`, PDO, QueryBuilder e cliente HTTP permanecem confinados a essas camadas.

## ◇ Contratos

### `DocumentLoader` e `TextSplitter`

```text
public function load(): iterable;
public function split(Document $document): iterable;
```

O loader produz `Document` incrementalmente; o splitter produz `Chunk` na ordem do documento. `TextFileLoader` e `RecursiveTextSplitter` são as implementações incluídas.

### `EmbeddingProvider`

```text
public function space(): EmbeddingSpace;
public function embed(string $text, string $tenantId): Embedding;
public function embedBatch(EmbeddingBatchRequest $request): array;
```

O provider processa um lote e não controla concorrência global. `embedBatch()` retorna um `Embedding` por texto, na mesma ordem e no `expectedSpace`.

### `BatchEmbeddingExecutor`

```text
public function execute(iterable $batches, EmbeddingProvider $provider): iterable;
```

Recebe lotes de `Chunk` e produz `BatchEmbeddingResult`. O executor pode limitar operações simultâneas sem expor `Future` no retorno.

### `VectorStore`

```text
public function storeBatch(array $chunks): void;
public function search(VectorSearchQuery $query): array;
```

Persiste `list<EmbeddedChunk>` e retorna `list<VectorSearchResult>`, respeitando tenant, collection, status e espaço vetorial.

### Modelos de linguagem

```text
public function complete(array $messages): string; // LanguageModel
public function generationFingerprint(): string;  // CacheableLanguageModel
public function stream(array $messages): iterable; // StreamingLanguageModel
```

`LanguageModel` e `StreamingLanguageModel` são independentes. `stream()` só pode produzir `AnswerDelta` quando o transporte for incremental de verdade.

## □ Documentos e chunks

### `Document`

```php
new Document(
    id: 'manual-2026',
    tenantId: 'acme',
    content: 'Conteúdo do documento',
    metadata: ['source' => 'manual.md'],
    collection: 'support',
    status: 'active',
);
```

`id`, `tenantId`, `content`, `collection` e `status` são validados.

### `Chunk`

Trecho com `id`, `documentId`, `tenantId`, `content`, posição não negativa, metadata, collection e status. O splitter gera ID estável em função do documento, posição e conteúdo.

### Utilitários

- `RecursiveTextSplitter(int $chunkSize = 1000, int $overlap = 150)`: incremental; exige tamanho positivo e overlap menor que o tamanho.
- `TextNormalizer::normalize(string $text): string`: uniformiza quebras de linha e espaços.
- `Batcher::batches(iterable $items, int $size): iterable`: preserva chaves, inclui o lote final incompleto e não materializa toda a entrada.
- `TextFileLoader(string $path, string $tenantId)`: valida tenant e arquivo.

## ◈ Embeddings

### `EmbeddingSpace`

```php
$space = new EmbeddingSpace(
    provider: 'openai',
    model: 'text-embedding-3-small',
    dimensions: 1536,
    revision: '1',
    parameters: ['encoding_format' => 'float'],
);

$fingerprint = $space->fingerprint();
```

A identidade inclui provider, modelo, dimensões, revisão e parâmetros semanticamente relevantes em ordem determinística. Segredos e endpoints transitórios não devem entrar em `parameters`.

### `Embedding`

`new Embedding(array $values, EmbeddingSpace $space)` aceita somente números finitos e exige exatamente `space->dimensions` valores. `dimensions()` e `model()` são atalhos.

### `EmbeddingBatchRequest` e `EmbeddedChunk`

O request agrupa tenant, textos, espaço esperado e metadata; lote vazio é válido. `EmbeddedChunk` associa chunk e embedding validados, sem ID técnico de banco.

## ⇣ Ingestão

### `IngestionPipeline`

```php
$report = $pipeline->ingest($loader);
```

O construtor recebe splitter, provider, store, tamanho do lote, `Batcher` e executor. `ingest()` percorre entradas incrementalmente, valida ordem/quantidade/espaço e persiste resultados serialmente.

### Relatórios e execução

- `IngestionReport`: documentos/chunks, lotes iniciados/concluídos/persistidos, sequências persistidas/descartadas e estado completo/parcial. Métodos `empty()` e `with*()` criam novas instâncias.
- `BatchEmbeddingResult`: valida sequência, tipos e cardinalidade entre chunks e embeddings.
- `BatchWindowException`: informa lote que falhou e sequências iniciadas, concluídas e descartadas.
- `FiberBatchEmbeddingExecutor(int $concurrency = 4)`: janela limitada; cada resultado mantém sequência e chunks originais mesmo fora de ordem.

## ◎ Retrieval e RAG

### `RetrievalPolicy` e `VectorMetric`

```php
new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE, maximumDistance: 0.4);
```

O limite é positivo e a distância máxima, quando presente, é finita e não negativa. Métricas: `L2`, `INNER_PRODUCT`, `COSINE` e `L1`.

### Consulta e resultado

`VectorSearchQuery` reúne tenant obrigatório, embedding, política, collection opcional e status. `VectorSearchResult` contém `Chunk $chunk` e distância finita; nenhum ID físico é exposto.

### `Retriever`

`retrieve(Question $question): array` gera o embedding no tenant da pergunta e consulta o store com os filtros configurados.

### Objetos RAG

- `Question(string $content, string $tenantId)`: ambos obrigatórios.
- `Answer(string $content, array $sources = [])`: resposta final e fontes.
- `AnswerDelta(string $content, int $sequence = 0, bool $final = false)`: delta incremental, com sequência não negativa.
- `ChatMessage(Role $role, string $content)`: mensagem não vazia; `Role` possui `SYSTEM`, `USER` e `ASSISTANT`.

### `ContextPromptBuilder`

`build(Question $question, array $results): array` retorna mensagens de sistema e usuário, delimita fontes com IDs estáveis e normaliza delimitadores. Sua `version` participa do cache de LLM.

### `RagPipeline`

```php
$answer = $rag->ask(new Question('Qual é a política?', 'acme'));

foreach ($rag->stream(new Question('Resuma.', 'acme')) as $delta) {
    // $delta vem de um provider realmente incremental.
}
```

`ask()` aceita uma pergunta ou texto acompanhado de tenant. `stream()` exige um `StreamingLanguageModel` injetado separadamente; caso contrário lança `StreamingNotSupportedException`.

## ▣ PgVector

- `PgVectorSchema`: configura e valida identificadores de tabela e colunas.
- `PgVectorStore(QueryBuilder $query, PgVectorSchema $schema = new PgVectorSchema())`: persiste serialmente e busca. O upsert usa tenant + collection + chunk + fingerprint e não solicita `RETURNING` nem sequence.

O schema é provisionado externamente. Veja [schema](database-schema.md) e [Docker](docker-integration.md).

## ⇄ Providers HTTP

- `OpenAIEmbeddingProvider`: exige API key, modelo e dimensão; implementa somente `EmbeddingProvider`.
- `OllamaEmbeddingProvider`: exige modelo, dimensão e endpoint válido; implementa somente `EmbeddingProvider`.
- `OpenAILanguageModel`: implementa `CacheableLanguageModel`, não streaming.
- `JsonClient`: POST JSON materializado; respostas inválidas viram `ProviderException`.

Os providers atuais são buffered. Nenhum divide uma resposta completa em deltas.

## ⛁ Cache

- `CachedEmbeddingProvider`: chave por tenant, fingerprint e hash exato; deduplica o lote, envia apenas misses, valida hits e recompõe a ordem.
- `CachedLanguageModel`: recebe modelo, cache, tenant, versão do prompt, flag `enabled`, TTL e namespace. Desativado por padrão; falhas e streaming não são armazenados.

## ⚠ Exceções

Derivam de `ContextEngineException`: `InvalidEmbeddingException`, `ProviderException`, `IngestionException` e `StreamingNotSupportedException`. `IngestionException` expõe relatório parcial, documento, espaço e sequência que falhou. `BatchWindowException` é infraestrutura convertida em diagnóstico de ingestão.

## → Próximos passos

- [Guia de extensão](extension-guide.md)
- [Tratamento de erros](error-handling.md)
- [Segurança](security.md)
- [Solução de problemas](troubleshooting.md)
