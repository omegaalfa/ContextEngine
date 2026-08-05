# 🧯 Troubleshooting

| Sintoma | Causa provável | Diagnóstico e correção |
|---|---|---|
| `Expected N dimensions` | vetor/provider diferente do espaço | compare `EmbeddingSpace::dimensions` com resposta e `vector(n)` |
| tipo `vector` ausente | pgvector não habilitado | confirme `CREATE EXTENSION vector` no ambiente provisionado |
| conexão recusada | container/host/porta incorretos | rode healthcheck e confira `.env`; tente porta alternativa |
| porta ocupada | outra stack usa 54339/63809 | sobrescreva `CONTEXT_ENGINE_*_PORT` |
| Redis indisponível | serviço/senha incorretos | valide healthcheck e `CONTEXT_ENGINE_REDIS_PASSWORD` |
| 401/403 OpenAI | API key inválida | confirme secret e não registre Authorization em logs |
| cardinalidade diferente | provider não devolveu um vetor por texto | corrija implementação; o executor/pipeline rejeita o lote |
| modelo Ollama ausente | modelo não foi baixado | confira modelos locais e o nome passado ao construtor |
| streaming não suportado | provider atual não implementa streaming incremental | use `OpenAILanguageModel::stream()` ou `ask()` |
| integração em skip | flag opt-in ausente | defina `CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1` ou Redis equivalente |
| fingerprint mudou | revisão/parâmetro semântico mudou | compare provider/model/dimensions/revision/parameters |
| retrieval vazio | tenant, collection, status ou espaço divergente | inspecione o escopo e confirme dados com os mesmos campos |
| conteúdo duplicado | mesmo chunk em vários espaços | comportamento esperado do schema MVP |
| erro de conflito | PK/schema não coincide com store | use PK composta documentada e reprovisione a fixture |
| rate limit | batch/janela agressivos | reduza `batchSize`/`concurrency` e aplique retry no nível apropriado |
| timeout | rede/provider lento | configure o `AsyncHttpClient` injetado com timeouts adequados |
| `ingest()` permanece aguardando | cliente HTTP e executor usam loops diferentes | injete a mesma instância de `FiberEventLoop` nos dois componentes |

Para SQL, use logs seguros do QueryBuilder sem expor conteúdo sensível. Para problemas Docker, `docker compose ps` e `docker compose logs pgvector`/`redis` mostram healthchecks e inicialização.

Se a chamada trava justamente em `IngestionPipeline::ingest()`, revise primeiro a composição concorrente:

```text
um FiberEventLoop
├── AsyncHttpClient usado pelo EmbeddingProvider
└── FiberBatchEmbeddingExecutor usado pela IngestionPipeline
```

Um `AsyncHttpClient()` com loop implícito e um executor com outro loop não compartilham o mesmo scheduler. Veja a montagem completa em [Concorrência e backpressure](concurrency.md).

Para distinguir erro de configuração de recurso ainda ausente, consulte [Limitações e escopo](limitations.md). `OpenAILanguageModel` já oferece streaming incremental real; LLMs Ollama e Gemini seguem buffered. Embeddings Gemini e reranking ainda exigem implementação externa.
