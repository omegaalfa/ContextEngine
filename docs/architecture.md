# ✦ Arquitetura do ContextEngine

Este documento é o mapa técnico do `omegaalfa/context-engine`. Ele explica as fronteiras, os fluxos e as decisões que um novo contribuidor precisa conhecer antes de alterar o código. Para assinaturas individuais, consulte a [referência da API](api-reference.md).

## 1. Visão geral

O ContextEngine é uma biblioteca PHP para construir pipelines de contexto e RAG (*Retrieval-Augmented Generation*). Ela transforma documentos em trechos pesquisáveis, associa esses trechos a embeddings, armazena versões compatíveis no PostgreSQL/pgvector e usa o contexto recuperado para produzir respostas com um modelo de linguagem.

Os problemas centrais resolvidos são:

- ingestão incremental sem materializar todos os documentos ou chunks;
- batching e concorrência limitada para chamadas de embeddings;
- isolamento por tenant e collection;
- identificação rigorosa do espaço vetorial;
- persistência idempotente de múltiplas versões do mesmo chunk;
- retrieval com filtros aplicados no SQL;
- composição segura e diagnosticável do prompt;
- cache opcional sem contaminar domínio ou contratos;
- distinção explícita entre resposta buffered e streaming incremental real.

O fluxo de alto nível é:

```text
DocumentLoader
      ↓
TextSplitter
      ↓
Batcher
      ↓
BatchEmbeddingExecutor ───→ EmbeddingProvider
      ↓                         ↓
      └──── BatchEmbeddingResult
                    ↓
               VectorStore
                    ↓
                 Retriever ← Question + embedding
                    ↓
          ContextPromptBuilder
                    ↓
              LanguageModel
                    ↓
                  Answer
```

A ingestão e a consulta são fluxos separados. A ingestão produz estado vetorial durável. A consulta cria um embedding da pergunta no mesmo `EmbeddingSpace`, recupera evidências compatíveis e só então chama o modelo de linguagem.

### Diagrama arquitetural completo

```text
┌─────────────────────────────────────────────────────────────┐
│ APPLICATION                                                 │
│ API / CLI / Web / autenticação / autorização               │
└────────────────────────────┬────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────┐
│ CONTEXTENGINE                                               │
│                                                             │
│ INGESTÃO                                                    │
│ DocumentLoader → Document → IngestionPipeline               │
│                              ↓                              │
│                         TextSplitter → Chunk[]               │
│                              ↓                              │
│                  BatchEmbeddingExecutor                     │
│                              ↓                              │
│             CachedEmbeddingProvider (opcional)              │
│                 ↓ hit              ↓ miss                   │
│        PSR-16 CacheInterface   EmbeddingProvider             │
│                                      ↓                      │
│                         Embedding + EmbeddingSpace           │
│                                      ↓                      │
│                                 VectorStore                 │
│                                      ↓                      │
│                         PostgreSQL + pgvector                │
│                                                             │
│ CONSULTA RAG                                                │
│ Question → Retriever → EmbeddingProvider                    │
│               ↓              ↓                              │
│               └──────── VectorStore                         │
│                          filtros SQL:                       │
│                          tenant + collection? + status      │
│                          + provider + model + dimensions    │
│                          + revision + fingerprint           │
│                              ↓                              │
│                         Top K chunks                        │
│                              ↓                              │
│                    ContextPromptBuilder                     │
│                              ↓                              │
│             CachedLanguageModel (opcional, desligado)       │
│                 ↓ hit              ↓ miss                   │
│        PSR-16 CacheInterface      LanguageModel              │
│                                      ↓                      │
│                              Answer + Sources               │
└─────────────────────────────────────────────────────────────┘
```

`collection` só entra no SQL quando configurada. Cache não altera os contratos: decorators podem envolver provider/modelo, enquanto pipelines continuam dependentes das mesmas interfaces. A aplicação externa controla endpoints, autenticação e apresentação.

## 2. Organização do projeto

### `Cache`

Contém decorators PSR-16 para embeddings e respostas de LLM. Existe para adicionar cache por composição, sem colocar lógica de cache nos providers ou no domínio. Depende dos contratos, dos objetos de embedding/prompt e de `Psr\SimpleCache\CacheInterface`; providers e aplicações podem depender desses decorators. Nunca deve conhecer PgVector, QueryBuilder, Futures ou regras de persistência. Cache não é fonte de verdade.

### `Chunk`

Contém `Chunk`, a unidade lógica de conteúdo persistida e recuperada. Existe para separar um trecho endereçável do documento original. Depende apenas de tipos nativos e validações próprias; splitter, ingestão, vector store, retrieval e respostas dependem dele. Nunca deve conhecer provider, banco, cliente HTTP, cache ou executor concorrente.

### `Contract`

Define as portas da biblioteca: `DocumentLoader`, `TextSplitter`, `EmbeddingProvider`, `BatchEmbeddingExecutor`, `VectorStore`, `LanguageModel`, `CacheableLanguageModel` e `StreamingLanguageModel`. Existe para inverter dependências: orquestradores conhecem capacidades, não implementações. Depende de objetos de domínio usados nas assinaturas; aplicação e infraestrutura dependem dos contratos. Nunca deve mencionar PDO, QueryBuilder, HttpClient, pgvector, Redis, `Future` ou detalhes de um fornecedor.

### `Document`

Contém `Document`, entrada validada da ingestão com ID, tenant, conteúdo, metadata, collection e status. Existe para tornar contexto e escopo explícitos desde a borda do sistema. Loaders e splitters o usam; ele não depende dessas abstrações. Nunca deve conhecer chunks derivados, embeddings, persistência ou modelos de linguagem.

### `Embedding`

Contém `EmbeddingSpace`, `Embedding`, `EmbeddingBatchRequest` e `EmbeddedChunk`. O módulo representa a identidade semântica do vetor, seus valores e associações necessárias ao processamento. Depende de `Chunk` apenas em `EmbeddedChunk`; providers, cache, ingestão, retrieval e stores dependem dele. Nunca conhece endpoint, API key, transporte HTTP, SQL ou mecanismo de concorrência.

`EmbeddingSpace` é especialmente importante: provider, model, dimensions, revision e parâmetros semanticamente relevantes formam uma identidade imutável e um fingerprint determinístico.

### `Exception`

Contém a hierarquia pública de falhas: `ContextEngineException`, `ProviderException`, `InvalidEmbeddingException`, `IngestionException` e `StreamingNotSupportedException`. Existe para permitir tratamento consistente e preservar diagnóstico, como o relatório parcial de ingestão. Pode depender dos value objects necessários para contextualizar uma falha. Nunca deve implementar recuperação, fazer I/O ou esconder a causa original.

### `Infrastructure`

Contém implementações técnicas que não pertencem ao domínio. Hoje abriga `FiberBatchEmbeddingExecutor`, que usa `omegaalfa/fiber-event-loop`. Existe para isolar mecanismo de concorrência e permitir substituição por outro executor. Depende dos contratos e tipos de ingestão, além da biblioteca de event loop; `IngestionPipeline` pode receber essa implementação via contrato. Nunca deve alterar regras de identidade vetorial ou fazer a persistência por conta própria.

### `Ingestion`

Contém `IngestionPipeline`, `IngestionReport`, `BatchEmbeddingResult` e `BatchWindowException`. É a camada de aplicação que coordena loader, splitter, batching, executor, provider e store. Depende somente das portas e objetos necessários ao caso de uso, embora ofereça o executor Fiber como padrão de construção. Nunca deve conhecer HTTP, Redis, SQL, PDO ou estruturas internas de um provider.

### `Loader`

Contém adaptadores de entrada, atualmente `TextFileLoader`. Existe para converter uma fonte externa em um fluxo de `Document`. Depende do contrato `DocumentLoader` e de `Document`; a ingestão depende apenas do contrato. Nunca deve dividir texto, gerar embedding, controlar concorrência ou persistir dados.

### `Prompt`

Contém `ChatMessage`, `Role` e `ContextPromptBuilder`. Existe para converter pergunta e resultados recuperados em mensagens explícitas para o modelo. Depende de `Question` e `VectorSearchResult`; `RagPipeline` depende do builder. Nunca deve buscar vetores, chamar providers ou alegar que delimitadores eliminam completamente prompt injection.

### `Provider`

Contém adaptadores para serviços externos: OpenAI, Ollama e o cliente JSON compartilhado. Existe para traduzir contratos estáveis em requisições e payloads de fornecedores. Depende de HttpClient, contratos e objetos de embedding/prompt; ingestão, retrieval e RAG os recebem pelas interfaces. Nunca deve controlar a janela global de ingestão, persistir chunks, expor tipos do HttpClient ou simular streaming.

### `Rag`

Contém `Question`, `Answer`, `AnswerDelta` e `RagPipeline`. É a fachada do caso de uso de resposta. Depende de `Retriever`, `ContextPromptBuilder` e contratos de modelo. Aplicações normalmente entram pela pipeline. Nunca deve conhecer pgvector, QueryBuilder, HTTP, cache concreto ou `Future`.

### `Retrieval`

Contém `Retriever`, `RetrievalPolicy`, `VectorMetric`, `VectorSearchQuery` e `VectorSearchResult`. Existe para representar uma busca vetorial independente do banco. Depende de `EmbeddingProvider`, `VectorStore`, `Embedding` e `Chunk`; RAG depende do retriever, e stores implementam suas consultas. Nunca deve conhecer o enum de métrica do QueryBuilder, operadores SQL ou sintaxe pgvector.

### `Splitter`

Contém `RecursiveTextSplitter`. Existe para derivar chunks determinísticos, sobrepostos e incrementais. Depende de `Document`, `Chunk`, `TextSplitter` e `TextNormalizer`; a ingestão o usa pelo contrato. Nunca deve carregar arquivos, chamar provider ou guardar chunks.

### `Support`

Contém utilitários pequenos e sem estado: `Batcher` e `TextNormalizer`. Existe para centralizar algoritmos reutilizáveis sem criar dependências de infraestrutura. Splitter e ingestão dependem deles. Nunca deve se tornar um depósito de regras de domínio, serviços externos ou estado global.

### `VectorStore`

Contém `PgVectorStore` e `PgVectorSchema`. Existe para adaptar a porta `VectorStore` ao QueryBuilder e ao pgvector. Depende de objetos de embedding/retrieval e da infraestrutura SQL. A ingestão e o retriever dependem somente do contrato, não desta pasta. Nunca deve vazar QueryBuilder, seus enums, `Vector`, PDO ou linhas cruas para o domínio.

## 3. Fluxo completo de ingestão

```text
Document → Chunk(s) → lote(s) → embedding(s) → validação
   → persistência serial → versão idempotente → relatório
```

1. **Carregamento.** `IngestionPipeline::ingest()` recebe um `DocumentLoader`. `load()` entrega um `iterable<Document>`; portanto, uma implementação pode ler arquivos, filas ou APIs progressivamente.
2. **Divisão.** Para cada `Document`, a pipeline chama `TextSplitter::split()`. `RecursiveTextSplitter` normaliza com `TextNormalizer`, cria conteúdo sobreposto e produz `Chunk` por generator. Tenant, collection, status e metadata seguem com o chunk.
3. **Batching.** `Batcher::batches()` consome o iterable somente conforme necessário. Ele preserva as chaves dentro do lote, produz o último lote incompleto e propaga exceções da origem.
4. **Criação da janela.** `BatchEmbeddingExecutor::execute()` recebe os lotes. `FiberBatchEmbeddingExecutor` inicia no máximo `concurrency` lotes por janela. Para cada lote cria um `EmbeddingBatchRequest` com tenant, textos, espaço esperado e sequência.
5. **Embeddings.** `EmbeddingProvider::embedBatch()` processa exatamente um lote. A concorrência não pertence ao provider. O provider retorna uma lista ordenada de `Embedding`.
6. **Conclusão e validação.** O executor aguarda cada operação, valida que o retorno é uma lista, que a cardinalidade corresponde aos chunks e que cada item é `Embedding`. `BatchEmbeddingResult` conserva a sequência, os chunks originais e os vetores, impedindo associação por ordem de conclusão HTTP.
7. **Compatibilidade.** A pipeline compara o fingerprint de cada resultado com `EmbeddingProvider::space()`. Misturar provider, modelo, dimensão, revisão ou configuração falha antes da persistência.
8. **Persistência serial.** Para cada resultado emitido, a pipeline monta `EmbeddedChunk[]` e chama `VectorStore::storeBatch()`. Nenhuma transação é aberta antes ou durante as chamadas HTTP; uma única conexão PDO não é usada concorrentemente.
9. **Versionamento e idempotência.** `PgVectorStore` faz upsert pela identidade `(tenant_id, collection, chunk_id, embedding_space_fingerprint)`. Reexecutar o mesmo chunk no mesmo espaço atualiza a linha; mudar tenant, collection ou espaço cria uma versão independente.
10. **Cache opcional.** Quando `CachedEmbeddingProvider` envolve o provider, ele consulta cada texto por tenant + espaço + hash exato, deduplica repetições, envia somente misses e recompõe a ordem. A pipeline não sabe se existe cache.
11. **Relatório.** Ao sucesso, `IngestionReport` informa documentos, chunks e lotes processados/persistidos. Em falha, `IngestionException` contém um relatório parcial, documento, espaço e sequência.

Se um lote falha no meio da janela, o executor drena as operações já iniciadas para liberar recursos, não abre nova janela e descarta resultados posteriores ao primeiro erro. Lotes já entregues e persistidos permanecem duráveis. A retomada é idempotente por causa do upsert composto.

## 4. Fluxo completo de consulta (RAG)

```text
Question
   ↓
Embedding da pergunta + EmbeddingSpace
   ↓
VectorSearchQuery
   ↓
VectorStore::search()
   ↓
list<VectorSearchResult>
   ↓
ContextPromptBuilder → list<ChatMessage>
   ↓
LanguageModel::complete()
   ↓
Answer
```

1. `RagPipeline::ask()` recebe um `Question` pronto ou uma string acompanhada de `tenantId`; neste segundo caso cria `Question`.
2. `Retriever::retrieve()` chama `EmbeddingProvider::embed()` com conteúdo e tenant da pergunta, criando `Embedding` no espaço do provider.
3. O retriever cria `VectorSearchQuery` com tenant, embedding, `RetrievalPolicy`, collection opcional e status.
4. `PgVectorStore::search()` converte a métrica pública para a métrica do QueryBuilder e monta nearest-neighbor search. Tenant, status, provider, model, dimensions, revision e fingerprint entram no SQL; collection também entra quando configurada.
5. Cada linha válida vira `Chunk` e depois `VectorSearchResult`. `maximumDistance`, se configurada, elimina resultados acima do limiar após a consulta limitada.
6. `ContextPromptBuilder::build()` recebe `Question` e resultados. Ele cria duas `ChatMessage`: uma de sistema e uma de usuário com a pergunta e fontes claramente delimitadas, identificadas e tratadas como dados não confiáveis.
7. `LanguageModel::complete()` recebe as mensagens e devolve texto. Se houver `CachedLanguageModel` habilitado, a consulta pode ser atendida pelo cache usando tenant, identidade de geração, mensagens e versão do prompt.
8. A pipeline cria `Answer` com conteúdo e os mesmos `VectorSearchResult` usados como fontes.

Em `RagPipeline::stream()`, as etapas de retrieval e prompt são iguais. A última etapa usa o contrato independente `StreamingLanguageModel` e produz `AnswerDelta`; não existe conversão de um `Answer` completo em deltas.

## 5. Dependências entre módulos

A organização segue dependência dirigida para dentro: regras e modelos centrais não importam detalhes técnicos.

```text
                 ┌──────────────────────────────────────┐
                 │ Infrastructure / Providers / Stores │
                 └──────────────────┬───────────────────┘
                                    │ implementam
                 ┌──────────────────▼───────────────────┐
                 │              Contracts               │
                 └──────────────────┬───────────────────┘
                                    │ usam tipos
                 ┌──────────────────▼───────────────────┐
                 │ Domain: Document, Chunk, Embedding,  │
                 │ Retrieval, Question, Answer          │
                 └──────────────────────────────────────┘

Application services: IngestionPipeline, Retriever e RagPipeline
dependem de Contracts + Domain e coordenam o fluxo.
```

Dependências permitidas:

- domínio → tipos nativos e validações próprias;
- contratos → domínio usado nas assinaturas;
- aplicação → contratos e domínio;
- infraestrutura → contratos, domínio e bibliotecas externas;
- decorators → contratos, domínio e PSR-16.

Dependências proibidas:

- domínio ou contratos → QueryBuilder, PDO, HttpClient, Redis, pgvector, FiberEventLoop ou `Future`;
- providers → `IngestionPipeline`, executor global ou vector store;
- retrieval/RAG → SQL ou tipos específicos de transporte;
- cache → persistência vetorial ou streaming;
- splitter/loader → modelo de linguagem.

Uma dependência externa nova deve entrar atrás de um contrato existente ou em uma implementação de infraestrutura. Alterar o contrato só se justifica quando a capacidade é geral, não quando um fornecedor possui um detalhe particular.

## 6. Providers

### `EmbeddingProvider`

Implemente quando um serviço transforma texto em vetor. A implementação deve declarar `EmbeddingSpace`, validar configuração, processar um lote, preservar ordem e retornar a cardinalidade exata. Não implemente nele agendamento de vários lotes, retry global, persistência ou isolamento transacional.

### `LanguageModel`

Implemente quando um serviço recebe `list<ChatMessage>` e retorna uma resposta completa. Se a resposta puder ser cacheada, implemente `CacheableLanguageModel` e forneça `generationFingerprint()` que represente modelo e parâmetros de geração.

### `StreamingLanguageModel`

Implemente somente quando o transporte entrega fragmentos antes da resposta completa. É um contrato independente porque completar e transmitir são capacidades diferentes. Um provider pode implementar ambos por meio de duas interfaces, mas uma não implica a outra.

`OpenAILanguageModel` atual usa `AsyncHttpClient` de modo buffered: o corpo é materializado antes do parse. Por isso ele implementa `CacheableLanguageModel`, não `StreamingLanguageModel`. Dividir a string pronta em `AnswerDelta` criaria apenas uma aparência de streaming, sem reduzir latência ou memória. Quando a aplicação chama `RagPipeline::stream()` sem provider incremental, recebe `StreamingNotSupportedException`.

## 7. Persistência

`PgVectorStore` é o único adaptador pgvector. `PgVectorSchema` configura nomes de tabela/colunas e valida identificadores antes de qualquer interpolação. A biblioteca não cria extensão, tabela ou índice em runtime; isso pertence à aplicação ou à fixture de integração.

### Identidade e versionamento

A chave primária de domínio é:

```text
(tenant_id, collection, chunk_id, embedding_space_fingerprint)
```

Tenant e collection participam porque `chunk_id` não é assumido global entre todos os escopos. Não existe `BIGSERIAL` nem ID técnico público. O fingerprint identifica integralmente `EmbeddingSpace`; colunas separadas de provider, model, dimensions e revision continuam disponíveis para filtros e diagnóstico.

### UPSERT

`storeBatch()` exige um lote de um único espaço, converte valores para o tipo `Vector` do QueryBuilder e executa `insertBatch()->onConflict(...)->doUpdate(...)->execute()`. Mesma identidade atualiza conteúdo, metadata, status e vetor. Espaço diferente nunca sobrescreve o anterior. Não há `RETURNING`, `lastInsertId()` ou dependência de sequence.

### Retrieval

`RetrievalPolicy` define limite, `VectorMetric` e distância máxima opcional. A métrica pertence ao ContextEngine; o store a traduz para o enum do QueryBuilder. O SQL filtra tenant, status, provider, model, dimensions, revision, fingerprint e collection quando definida. Assim, vetores incompatíveis nem chegam à memória.

O schema de integração usa `vector(3)` para testes determinísticos. Uma aplicação deve provisionar a dimensão adequada ao provider. Índices B-tree apoiam filtros de tenant/collection/status/espaço, enquanto índices HNSW por métrica aceleram nearest-neighbor em produção. A escolha exata depende da distribuição e volume; consulte [schema](database-schema.md) e [performance](performance.md).

## 8. Concorrência

Concorrência é política de execução da ingestão, não propriedade do embedding ou do domínio. Ela fica em `Infrastructure` porque depende de FiberEventLoop e de seu `Future`.

`BatchEmbeddingExecutor` existe para que `IngestionPipeline` possa solicitar “execute estes lotes” sem conhecer fibers. A implementação padrão cria uma janela limitada, inicia até `concurrency` operações HTTP, aguarda e valida cada lote, emite resultados para persistência serial e só então avança para a próxima janela.

`Future` nunca aparece na API pública porque:

- acoplaria consumidores a um event loop específico;
- transferiria detalhes de cancelamento e await para domínio/providers;
- dificultaria substituir a estratégia de execução;
- permitiria persistência concorrente acidental pela mesma conexão.

A API permanece síncrona e baseada em `iterable`. O executor absorve o mecanismo assíncrono e retorna `BatchEmbeddingResult`, um tipo próprio e diagnosticável.

## 9. Cache

Cache entra como decorator, sempre por injeção explícita:

```text
Ingestion/Retriever → CachedEmbeddingProvider → EmbeddingProvider real
RagPipeline         → CachedLanguageModel     → LanguageModel real
```

`CachedEmbeddingProvider` armazena embeddings válidos por tenant, fingerprint e hash exato do texto. Em lote, hits e misses são avaliados individualmente, duplicatas geram uma única chamada e a ordem original é reconstruída.

`CachedLanguageModel` fica desativado por padrão porque respostas podem ser não determinísticas. Quando habilitado, sua chave contém tenant, fingerprint da geração, mensagens completas — incluindo system prompt, user prompt e contexto — e versão do prompt builder.

Nunca devem ser cacheados:

- falhas, respostas parciais ou embeddings inválidos;
- valores com espaço incompatível;
- respostas entre tenants sem namespace de isolamento;
- secrets ou credenciais como parte legível da chave;
- streaming.

Streaming não passa pelo cache porque reproduzir deltas armazenados não preserva temporização, transporte ou semântica incremental; cachear somente o resultado final também mudaria o contrato observado. Se a aplicação precisa cachear a resposta completa, deve usar o caminho `complete()` separadamente.

## 10. Pontos de extensão

| Ponto | Quando criar uma implementação |
|---|---|
| `DocumentLoader` | Ler S3, banco, fila, PDF já extraído ou API paginada como `Document` incremental. |
| `TextSplitter` | Dividir Markdown por headings, código por AST ou texto por tokens do modelo. |
| `EmbeddingProvider` | Integrar outro fornecedor ou modelo local, declarando espaço e lote corretamente. |
| `BatchEmbeddingExecutor` | Trocar FiberEventLoop por execução sequencial, filas ou outro runtime, sem mudar a API. |
| `LanguageModel` | Usar Anthropic, Gemini, modelo local ou gateway corporativo com resposta completa. |
| `StreamingLanguageModel` | Usar SSE/chunked transport que realmente entregue deltas incrementais. |
| `VectorStore` | Integrar outro banco vetorial mantendo filtros e compatibilidade de espaço. |
| `CacheInterface` | Usar Redis, memória ou cache distribuído compatível com PSR-16. |

Ao estender, valide cedo, mantenha tenant explícito, não exponha tipos do SDK externo e preserve as invariantes documentadas nos contratos. Exemplos-base estão no [guia de extensão](extension-guide.md).

## 11. Decisões arquiteturais

### API síncrona, sem Promise pública

**Escolha:** contratos retornam valores ou iterables. **Alternativa descartada:** fazer providers e pipelines retornarem Promise/Future. Isso obrigaria toda aplicação a adotar o runtime assíncrono escolhido pela biblioteca, apesar de a concorrência ser necessária apenas em uma parte da ingestão.

### `Future` restrito à infraestrutura

**Escolha:** `FiberBatchEmbeddingExecutor` converte Futures em `BatchEmbeddingResult`. **Alternativa descartada:** propagar Future pelo provider ou `RagPipeline`; isso vazaria cancelamento, scheduling e await para camadas sem essa responsabilidade.

### QueryBuilder não vaza para o domínio

**Escolha:** somente `PgVectorStore` conhece QueryBuilder. **Alternativa descartada:** usar objetos de consulta ou enums SQL em `VectorSearchQuery`; isso impediria stores alternativos e faria política de retrieval depender do PostgreSQL.

### HttpClient não vaza

**Escolha:** providers encapsulam cliente, headers e JSON. **Alternativa descartada:** aceitar/retornar response/request HTTP nos contratos; consumidores teriam de conhecer transporte e detalhes do fornecedor.

### `VectorMetric` pertence ao ContextEngine

**Escolha:** retrieval possui enum próprio e o store traduz. **Alternativa descartada:** reutilizar o enum do QueryBuilder, que inverteria a dependência e contaminaria a API pública com infraestrutura.

### Domínio não conhece PgVector

**Escolha:** domínio trabalha com `Embedding`, arrays numéricos e `VectorStore`. **Alternativa descartada:** guardar `PgVector\Vector` em `Embedding`; isso impediria providers e stores independentes e deslocaria validações para uma biblioteca SQL.

### Streaming buffered é recusado

**Escolha:** contratos de complete e stream são independentes; provider buffered não anuncia streaming. **Alternativa descartada:** fatiar a resposta completa em deltas. Essa solução não seria incremental e enganaria consumidores sobre latência e uso de memória.

### Generators em vez de `omegaalfa/collection`

**Escolha:** `Batcher`, splitter e loaders usam generators nativos. Eles já oferecem consumo incremental, preservação de chaves, lote final, propagação natural de exceções e iteração única com pouca abstração. A inspeção de `LazySequence` não encontrou método que eliminasse lógica relevante do `Batcher`. **Alternativa descartada:** adicionar a coleção apenas por uniformidade do ecossistema. Ela permanece em `suggest` para composição na aplicação.

### Lazy object sob responsabilidade da aplicação

**Escolha:** o núcleo recebe dependências construídas por injeção. **Alternativa descartada:** envolver providers em proxies/ghosts internamente. Isso atrasaria erros estruturais de API key, endpoint, modelo ou dimensão e tornaria a inicialização menos previsível. `omegaalfa/lazy-object` permanece em `suggest` para consumidores que tenham um objeto comprovadamente caro.

### Persistência serial

**Escolha:** chamadas HTTP podem concorrer, mas resultados são persistidos serialmente. **Alternativa descartada:** compartilhar uma conexão PDO entre Futures ou manter transação aberta durante rede, o que aumentaria contenção e risco de falha transacional.

### Identidade vetorial completa

**Escolha:** provider + model + dimensions + revision + configuração determinística formam `EmbeddingSpace`. **Alternativa descartada:** identificar apenas pelo nome do modelo; fornecedores/configurações diferentes poderiam misturar vetores semanticamente incompatíveis.

## 12. Roadmap

Ainda não fazem parte do núcleo:

- providers adicionais de embeddings e LLM;
- streaming SSE/chunked oficial nos providers atuais;
- reranking lexical, neural ou cross-encoder;
- busca híbrida vetorial + full-text;
- loaders estruturados para PDF, HTML, Markdown, bancos e object storage;
- splitters baseados em tokens, semântica ou estrutura de código;
- filtros arbitrários de metadata além do escopo atual;
- observabilidade padronizada com métricas, tracing e eventos;
- estratégias integradas de retry, backoff e rate limiting;
- migrações ou provisionamento de schema em runtime;
- suporte nativo a outros bancos vetoriais;
- cache automático de streaming;
- compartilhamento de cache de embeddings entre tenants.

Itens do roadmap devem preservar as fronteiras acima. Uma funcionalidade de fornecedor começa como adaptador; uma capacidade geral pode justificar contrato novo após comprovação em mais de uma implementação.

---

## Mapa de leitura para contribuidores

1. Leia [conceitos centrais](core-concepts.md) e este documento.
2. Consulte [ingestão](ingestion.md) ou [RAG](rag-pipeline.md), conforme o fluxo alterado.
3. Confira invariantes na [referência da API](api-reference.md).
4. Leia [testes](testing.md), [segurança](security.md) e [tratamento de erros](error-handling.md).
5. Antes de propor infraestrutura nova, valide as dependências permitidas e proibidas da seção 5.
