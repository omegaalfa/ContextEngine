# ✦ Referência da API pública

Esta página descreve a superfície pública de `Omegaalfa\\ContextEngine`. Tipos `readonly` são imutáveis e argumentos inválidos geram exceções imediatamente.

> Classes em `Infrastructure`, `Provider` e `VectorStore` são adaptadores públicos, não domínio. `Future`, PDO, QueryBuilder e cliente HTTP permanecem confinados a essas camadas.

## ⚡ Composição tipada

`Bootstrap::create(ContextEngineConfig, Closure): ContextEngineContext` oferece a composição padrão Ollama + pgvector. A closure recebe o `AsyncHttpClient` compartilhado e deve produzir um `LanguageModel`. O Bootstrap não utiliza container ou Service Locator; o contexto expõe apenas `retriever`, `ingestion`, `rag`, `embeddings` e `store`. Veja [Bootstrap](bootstrap.md).

## ◇ Contratos

### `DocumentLoader` e `TextSplitter`

```text
public function load(): iterable;
public function fingerprint(): string; // TextSplitter
public function split(Document $document): iterable;
```

O loader produz `Document` incrementalmente; o splitter produz `Chunk` na ordem do documento. O fingerprint identifica algoritmo e configurações que alteram os chunks e participa de `DocumentVersion`. `TextFileLoader` e `RecursiveTextSplitter` são as implementações incluídas.

Para PDF textual, `PdfTextExtractor::extract(string): iterable<ExtractedPdfPage>` separa extração física da criação de documentos. `PopplerPdfTextExtractor` usa `pdftotext` e `PdfDocumentLoader` agrupa páginas em janelas antes de produzir `Document`. OCR não está incluído.

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
public function deleteChunk(ChunkDeleteQuery $query): int;
public function deleteDocument(DocumentDeleteQuery $query): int;
public function clearCollection(CollectionDeleteQuery $query): int;
```

Persiste `list<EmbeddedChunk>`, retorna `list<VectorSearchResult>` e oferece manutenção por chunk, filtros de documento e collection. Toda exclusão exige tenant. `deleteChunk()` exige também collection e espaço vetorial. Em `DocumentDeleteQuery`, collection, documento e espaço são filtros opcionais; omitir todos remove os vetores do tenant informado. Os métodos retornam a quantidade de linhas removidas.

### `VersionedVectorStore`

Especializa `VectorStore` para o `IngestionPipeline`:

```text
public function beginVersion(DocumentVersion $version): void;
public function stageBatch(DocumentVersion $version, array $chunks): void;
public function activateVersion(DocumentVersion $version): void;
public function failVersion(DocumentVersion $version): void;
```

`DocumentVersion` é imutável e determinística. Ele agora pode carregar status documental, revisão, vigência temporal e supersessão. `activateVersion()` deve trocar versões atomicamente; `stageBatch()` nunca pode tornar uma versão incompleta pesquisável.

### `DocumentVersion` e `DocumentVersionStatus`

```php
$version = new DocumentVersion(
    document: $document,
    space: $space,
    chunkingFingerprint: 'splitter-a',
    status: DocumentVersionStatus::ACTIVE,
    validFrom: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
    validUntil: new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    revision: 3,
    supersedesVersionId: 'prev-version',
);
```

O método `isValidAt()` indica se a versão está vigente em um instante específico. Essa capacidade é a base para o retrieval temporal e para auditoria futura.

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

- `RecursiveTextSplitter(int $chunkSize = 1000, int $overlap = 150)`: incremental; exige tamanho positivo e overlap menor que o tamanho, preserva cobertura integral do texto normalizado e usa limites semânticos sem criar lacunas.
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

O construtor exige splitter, provider, store e executor; tamanho do lote e `Batcher` são configuráveis. `ingest()` percorre entradas incrementalmente, valida ordem/quantidade/espaço e persiste resultados serialmente.

### Relatórios e execução

- `IngestionReport`: contadores incrementais, documentos ativados, versões falhas, sequências afetadas, falha pública sanitizada e estado completo/parcial.
- `IngestionFailure`: código estável, mensagem segura, documento e sequência opcionais; a causa técnica permanece em `IngestionException::getPrevious()`.
- `BatchExecutionProgress`: snapshot dos lotes agendados, iniciados, concluídos e descartados sem consumir antecipadamente toda a entrada.
- `BatchEmbeddingResult`: valida sequência, tipos e cardinalidade entre chunks e embeddings e carrega o snapshot de progresso.
- `BatchWindowException`: informa lote que falhou, sequências iniciadas/concluídas/descartadas e o progresso final da janela drenada.
- `FiberBatchEmbeddingExecutor(FiberEventLoop $loop, int $concurrency = 4)`: janela limitada; cada resultado mantém sequência e chunks originais mesmo fora de ordem. O loop é obrigatório; com provider baseado em `AsyncHttpClient`, injete no cliente a mesma instância usada pelo executor.

## ◎ Retrieval e RAG

### `RetrievalPolicy` e `VectorMetric`

```php
new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE, maximumDistance: 0.4);
```

O limite é positivo e a distância máxima, quando presente, é finita e não negativa. Métricas: `L2`, `INNER_PRODUCT`, `COSINE` e `L1`.

### Consulta e resultado

`VectorSearchQuery` reúne tenant obrigatório, embedding, política, collection opcional e status. `VectorSearchResult` contém `Chunk $chunk`, distância finita e, opcionalmente, uma `VersionedSourceProvenance` com `documentVersionId`, revisão, status, vigência temporal e supersessão. Essa proveniência é propagada ao `ContextPromptBuilder` através de atributos `document_version`, `revision`, `status`, `valid_from` e `valid_until` nas fontes do prompt.

### `Retriever`

`retrieve(Question $question): array` gera o embedding no tenant da pergunta e consulta o store com os filtros configurados. O construtor aceita uma política opcional de seleção de versões:

```php
$newRetriever = new Retriever(
    embeddings: $provider,
    store: $store,
    versionSelectionPolicy: VersionSelectionPolicy::validAt(new DateTimeImmutable('2026-01-01 00:00:00')),
);
```

Quando nenhuma política for fornecida, o comportamento permanece igual e usa a seleção padrão de versões ativas.

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
- `PgVectorStore(QueryBuilder $query, PgVectorSchema $schema = new PgVectorSchema())`: implementa `VersionedVectorStore`. O upsert usa tenant + collection + chunk + fingerprint + document version e não solicita `RETURNING` nem sequence.

O schema é provisionado externamente. Veja [schema](database-schema.md) e [Docker](docker-integration.md).

## ⇄ Providers HTTP

- `OpenAIEmbeddingProvider`: exige API key, modelo e dimensão; implementa somente `EmbeddingProvider`.
- `OllamaEmbeddingProvider`: exige modelo, dimensão e endpoint válido; implementa somente `EmbeddingProvider`.
- `OpenAILanguageModel`: implementa `CacheableLanguageModel` e `StreamingLanguageModel` (`stream()` incremental via SSE real).
- `OllamaLanguageModel`: usa `/api/chat` com `stream: false`; implementa `CacheableLanguageModel`, não streaming.
- `GeminiLanguageModel`: usa `generateContent`, traduz system/user/assistant para o formato Gemini e implementa `CacheableLanguageModel`, não streaming.
- `JsonClient`: POST JSON materializado; respostas inválidas viram `ProviderException`.

`OllamaLanguageModel` e `GeminiLanguageModel` seguem buffered. O `OpenAILanguageModel` usa streaming incremental real e não faz divisão artificial de resposta pronta.

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
