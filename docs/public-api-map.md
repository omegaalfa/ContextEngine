# 🗺️ Mapa completo da API pública

Esta página responde: **“qual classe resolve o meu problema?”** Você não precisa conhecer toda a biblioteca para começar.

| Nível | Para quem | Ponto de entrada |
|---|---|---|
| 🟢 Uso cotidiano | ingerir, buscar e responder | `ContextEngine` |
| 🟡 Configuração avançada | montar pipelines próprios | contratos e pipelines |
| 🔵 Extensão e diagnóstico | criar integrações e auditorias | políticas, DTOs e diagnósticos |

> Tipos `readonly` são caixas de dados imutáveis. Neles, o construtor cria e valida o objeto; não existe uma ação adicional para executar.

## 🟢 `ContextEngine`: comece aqui

**Problema resolvido:** monta provider, pgvector, ingestão, retrieval e RAG sem exigir conhecimento da arquitetura interna.

| Método | Explicação simples |
|---|---|
| `create()` | inicia a configuração com defaults e ambiente já carregado |
| `tenant()` | informa quem é o dono dos dados |
| `collection()` | separa documentos por aplicação ou assunto |
| `status()` | seleciona o estado documental usado |
| `ollama()` | configura embeddings e respostas locais |
| `openAi()` | configura embeddings OpenAI |
| `openAiLanguageModel()` | configura o modelo OpenAI de resposta |
| `ingestion()` | ajusta lotes, concorrência e chunks |
| `retrieval()` | ajusta busca, modo híbrido, pesos e limites |
| `redis()` | configura cache Redis |
| `build()` | valida tudo e cria o contexto executável |
| `ingest()` | lê, divide, vetoriza e armazena documentos |
| `search()` | recupera chunks relevantes |
| `ask()` | recupera contexto e produz uma resposta |
| `stream()` | entrega resposta incremental; falha antes do retrieval se o modelo não suportar streaming |
| `searchWithDiagnostics()` | busca e mostra como o ranking foi formado |
| `askWithDiagnostics()` | responde e mostra retrieval, prompt e tempos |
| `withCustomComponents()` | substitui a composição para casos avançados |
| `withLanguageModelFactory()` | injeta uma fábrica própria de modelo |

Veja [High-Level API](high-level-api.md).

## 📄 Documentos, loaders e chunks

| Tipo | Problema resolvido | Métodos de ação |
|---|---|---|
| `Document` | representa conteúdo original com tenant e metadados | objeto imutável |
| `Chunk` | representa um trecho recuperável | objeto imutável |
| `DocumentLoader` | contrato para novas origens de documentos | `load()` |
| `TextFileLoader` | lê arquivos de texto | `load()` |
| `PdfDocumentLoader` | agrupa páginas de PDF em documentos lógicos | `load()` |
| `PdfTextExtractor` | contrato de extração física de PDF | `extract()` |
| `PopplerPdfTextExtractor` | extrai PDF textual com `pdftotext` | `extract()` |
| `ExtractedPdfPage` | guarda número e texto da página | objeto imutável |
| `TextFileGranularity` | escolhe a granularidade do arquivo | enum |

## 📥 Ingestão estrutural

| Tipo | Problema resolvido | Métodos públicos |
|---|---|---|
| `IngestionPipeline` | coordena todo o processamento | `ingest()` |
| `TextSplitter` | contrato para dividir documentos | `fingerprint()`, `split()` |
| `StructuralTextSplitter` | corta por significado e estrutura | `fingerprint()`, `split()` |
| `RecursiveTextSplitter` | divisão textual com overlap | `fingerprint()`, `split()` |
| `ChunkBuilder` | transforma árvore lógica em chunks | `build()` |
| `ChunkingStrategy` | contrato de limite de chunk | `fingerprint()`, `fits()`, `split()` |
| `CharacterLimitStrategy` | limita por caracteres | `fingerprint()`, `fits()`, `split()` |
| `TokenLimitStrategy` | limita por tokens estimados | `fingerprint()`, `fits()`, `split()` |
| `BlockLimitStrategy` | limita por blocos estruturais | `fingerprint()`, `fits()`, `split()` |
| `TokenEstimator` | contrato de contagem aproximada | `estimate()`, `fingerprint()` |
| `HeuristicTokenEstimator` | estima tokens sem serviço externo | `estimate()`, `fingerprint()` |
| `ChunkMetadata` | reúne heading, páginas e estrutura | `toArray()` |

### Parsers

`DocumentParser::parse()` é o contrato. `PdfParser`, `MarkdownParser`, `HtmlParser`, `JsonParser`, `XmlParser`, `PhpParser` e `PlainTextParser` transformam formatos específicos em árvore lógica. `ParserRegistry::parserFor()` escolhe o parser e `fingerprint()` identifica a configuração.

`DocumentNode` é a raiz e `SectionNode` agrupa seções. `HeadingNode`, `ParagraphNode`, `ListNode`, `QuoteNode`, `CodeBlockNode`, `TableNode`, `FigureTextNode`, `DiagramTextNode` e `UnknownNode` descrevem o significado de cada bloco. `Node` e `LeafNode` são bases para extensões.

### Ruído, versões e execução

| Tipos | O que resolvem |
|---|---|
| `StructuralNoisePolicy`, `StructuralNoiseDecision`, `StructuralNoiseKind` | detectam artefatos de layout; ações: `classify()`, `fingerprint()`, `isNoise()` |
| `DocumentVersion`, `DocumentVersionIdentity`, `DocumentVersionStatus` | identificam versões sem misturar processamentos incompatíveis |
| `VersionConflict`, `VersionConflictDetector` | detectam versões conflitantes com `detect()` |
| `VersionValidator`, `VersionValidationException` | validam transições com `validate()` |
| `IngestionReport`, `IngestionFailure`, `IngestionState` | explicam resultado completo ou parcial |
| `BatchEmbeddingResult`, `BatchExecutionProgress`, `BatchWindowException` | explicam lotes, progresso e falhas concorrentes |
| `IngestionException` | preserva relatório parcial e causa técnica |

## 🧠 Embeddings e modelos

| Tipo | Problema resolvido | Métodos |
|---|---|---|
| `EmbeddingProvider` | contrato independente de fornecedor | `space()`, `embed()`, `embedBatch()` |
| `EmbeddingSpace` | impede misturar modelos e dimensões | `fingerprint()` |
| `Embedding` | vetor validado no espaço correto | `dimensions()`, `model()` |
| `EmbeddingBatchRequest` | lote com tenant e espaço esperado | objeto imutável |
| `EmbeddedChunk` | associa chunk e vetor | objeto imutável |
| `OllamaEmbeddingProvider`, `OpenAIEmbeddingProvider` | integrações prontas | `space()`, `embed()`, `embedBatch()` |
| `EmbeddingResponseValidator` | rejeita respostas vetoriais inválidas | validação estática |
| `LanguageModel` | contrato para resposta completa | `complete()` |
| `CacheableLanguageModel` | identidade para cache | `generationFingerprint()` |
| `StreamingLanguageModel` | streaming realmente incremental | `stream()` |
| `OllamaLanguageModel`, `GeminiLanguageModel` | respostas completas | `complete()`, `generationFingerprint()` |
| `OpenAILanguageModel` | resposta completa e streaming | `complete()`, `stream()`, `generationFingerprint()` |
| `CohereReranker` | reordena candidatos com cross-encoder remoto | `rerank()`, `provider()`, `model()` |

`JsonClient::post()` envia JSON e converte falhas em `ProviderException`. `ProviderConfiguration` e `EmbeddingResponseValidator` protegem configurações e respostas inválidas.

## 🔎 Retrieval e busca híbrida

| Tipo | Problema resolvido | Métodos |
|---|---|---|
| `Retriever` | executa planejamento, buscas, RRF, evidência e seleção | `retrieve()`, `retrieveWithDiagnostics()` |
| `RetrievalPolicy`, `VectorMetric` | definem limite, distância e métrica | objeto / enum |
| `QueryRewriter` | contrato de reescrita | `rewrite()` |
| `IdentityQueryRewriter` | mantém a consulta original | `rewrite()` |
| `HeuristicQueryRewriter` | cria variações determinísticas | `rewrite()` |
| `ReciprocalRankFusion` | combina rankings vetorial e lexical | `fuse()` |
| `Reranker` | contrato para reordenar candidatos após o RRF | `rerank()` |
| `DeterministicTextualReranker` | baseline offline por cobertura textual | `rerank()` |
| `HybridEvidencePolicy` | rejeita hit isolado sem apoio | `select()` |
| `AdaptiveContextSelector` | aplica relevância adaptativa | `select()` |
| `ContextSelector` | respeita orçamento final | `select()` |
| `NeighborExpansion` | configura contexto vizinho | `enabled()` |
| `VersionSelectionPolicy` | escolhe versões ativas ou históricas | `active()`, `validAt()`, `allVersions()` |

`VectorSearchQuery`, `LexicalSearchQuery` e `NeighborSearchQuery` transportam filtros. `VectorSearchResult`, `RewrittenQueries`, `QueryMatch`, `QueryResultDiagnostic`, `RerankDiagnostic`, `ContextSelectionDiagnostic`, `ContextSelectionReason`, `RetrievalOutcome`, `RetrievalDiagnostics`, `VersionedRetrievalContext` e `VersionedSourceProvenance` explicam resultados e decisões. `ContextRelevancePolicy` configura a seleção adaptativa.

## 💬 RAG e prompt

| Tipo | Problema resolvido | Métodos |
|---|---|---|
| `Question`, `Answer`, `AnswerDelta` | pergunta, resposta e streaming | objetos imutáveis |
| `ContextPromptBuilder` | delimita contexto não confiável | `build()` |
| `ChatMessage`, `Role` | mensagens e papéis do chat | objeto / enum |
| `RagPipeline` | une retrieval, prompt e modelo | `ask()`, `askWithDiagnostics()`, `stream()` |
| `NoEvidencePolicy` | contrato para ausência de contexto | `response()` |
| `FixedNoEvidencePolicy` | resposta fixa e reproduzível | `response()` |
| `RagExecution`, `RagDiagnostics` | resposta, fontes, tempos e diagnóstico | objetos imutáveis |

## 🧪 Avaliação

| Tipo | Problema resolvido | Métodos |
|---|---|---|
| `EvaluationCase` | descreve pergunta e expectativas | objeto imutável |
| `EvaluationDataset` | valida e percorre casos | `count()`, `getIterator()` |
| `EvaluationDatasetLoader` | carrega golden em JSON | `fromJson()`, `fromFile()` |
| `ExpectedClaim` | fato com alternativas aceitas | objeto imutável |
| `RelevantEvidence` | evidência equivalente por texto | objeto imutável |
| `GoldenChunkMatcher`, `GoldenMatchMode` | ajuda a construir golden | `ids()` / enum |
| `RagEvaluator` | avalia o pipeline completo | `evaluate()` |
| `RetrievalEvaluator` | avalia somente retrieval | `evaluate()` |
| `AnswerEvaluationPolicy` | combina thresholds aplicáveis | `passes()` |
| `AnswerEvaluator`, `CaseEvaluator` | contratos para novas métricas | `evaluate()` |
| `DeterministicTextualGroundednessEvaluator` | mede apoio textual aproximado | `evaluate()` |
| `DeterministicGroundednessEvaluator` | nome compatível do anterior | `evaluate()` |
| `AnswerRelevanceEvaluator` | mede aderência à pergunta | `evaluate()` |
| `CorrectnessEvaluator` | compara claims do golden | `evaluate()` |
| `ExpectedTermsEvaluator` | procura termos configurados | `evaluate()` |
| `ExactMatchEvaluator` | compara resposta inteira | `evaluate()` |
| `RetrievalRecallEvaluator` | calcula métricas de recuperação | `evaluate()` |

`EvaluationScore`, `GroundednessResult`, `RetrievalMetrics`, `GenerationMetrics`, `EvaluationResult`, `EvaluationReport`, `RetrievalEvaluationResult` e `RetrievalEvaluationReport` transportam resultados. Os relatórios oferecem `metric()`, `denominator()`, `count()`, `positiveCases()`, `negativeCases()` e `averageLatencyMilliseconds()` quando aplicável. `EvaluationStatus` distingue aprovado, reprovado, erro e não aplicável. `SignificantTerms` e `TextComparison` são utilitários determinísticos.

## 🗄️ Stores, cache e infraestrutura

| Tipo | Problema resolvido | Métodos |
|---|---|---|
| `VectorStore` | persistência, busca e remoção | `storeBatch()`, `search()`, `deleteChunk()`, `deleteDocument()`, `clearCollection()` |
| `VersionedVectorStore` | ativação atômica de versões | `beginVersion()`, `stageBatch()`, `activateVersion()`, `failVersion()` |
| `LexicalSearchStore` | busca textual | `searchLexical()` |
| `NeighborAwareVectorStore` | busca vizinhos | `neighbors()` |
| `PgVectorStore` | implementação PostgreSQL + pgvector | métodos dos contratos acima |
| `PgVectorSchema` | adapta nomes de tabela e colunas | objeto imutável |
| `ChunkDeleteQuery`, `DocumentDeleteQuery`, `CollectionDeleteQuery` | exclusões seguras por tenant | objetos imutáveis |
| `CachedEmbeddingProvider` | reutiliza embeddings | `space()`, `embed()`, `embedBatch()` |
| `CachedLanguageModel` | reutiliza respostas | `complete()`, `generationFingerprint()` |
| `BatchEmbeddingExecutor` | contrato de execução concorrente | `execute()` |
| `FiberBatchEmbeddingExecutor` | limita concorrência com fibers | `execute()` |
| `Batcher` | cria lotes incrementais | `batches()` |
| `TextNormalizer` | uniformiza texto | `normalize()` |

## ⚙️ Configuração de baixo nível

`ContextEngineConfig`, `DatabaseConfig`, `OllamaConfig`, `IngestionConfig`, `RetrievalConfig`, `ProviderConfig` e `RedisConfig` são configurações validadas. `ContextEngineConfigFactory::fromEnvironment()` atende composição de baixo nível; aplicações novas devem preferir `ContextEngine::create()`.

`Bootstrap::create()` monta os componentes e `ContextEngineContext` expõe `ingest()`, `search()`, `ask()`, `stream()`, `searchWithDiagnostics()` e `askWithDiagnostics()`.

## ⚠️ Exceções

| Exceção | Significado |
|---|---|
| `ContextEngineException` | base das falhas da biblioteca |
| `ProviderException` | provider recusou ou respondeu incorretamente |
| `InvalidEmbeddingException` | vetor inválido |
| `IngestionException` | ingestão falhou com relatório parcial |
| `InsufficientContextException` | não há evidência suficiente |
| `StreamingNotSupportedException` | modelo sem streaming real |
| `PdfExtractionException` | falha ao extrair PDF |
| `IncompatibleVectorStoreSchemaException` | schema incompatível |

## 🧩 Extensão

Implemente apenas o contrato ligado ao seu problema: `DocumentLoader`, `DocumentParser`, `TextSplitter`, `ChunkingStrategy`, `EmbeddingProvider`, `VectorStore`, `LanguageModel`, `QueryRewriter`, `NoEvidencePolicy`, `AnswerEvaluator` ou `CaseEvaluator`.

Veja [Guia de extensão](extension-guide.md) para invariantes de tenant, embeddings e versionamento.
