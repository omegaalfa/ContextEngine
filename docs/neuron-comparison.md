# ContextEngine x Neuron AI

> Dois pipelines independentes usando o mesmo Ollama local.

## Executar

O pacote oficial `neuron-core/neuron-ai` est� somente em `require-dev`.

~~~bash
composer install

export NEURON_OLLAMA_URL=http://127.0.0.1:11434/api
export NEURON_EMBEDDING_MODEL=bge-m3
export NEURON_LANGUAGE_MODEL=qwen3:8b

php examples/simple-search.php
php examples/neuron-simple-search.php
php examples/simple-rag.php
php examples/neuron-simple-rag.php
~~~

Cada sa�da informa tempo total e pico de mem�ria. Execute cada comando mais de uma vez: carregamento do modelo e aquecimento do Ollama afetam a primeira medi��o.

Os exemplos Neuron usam `getenv()` do PHP. Eles n�o carregam o `.env` do ContextEngine e n�o importam EnvLoader.

## Componentes Neuron

| Papel | API verificada na vers�o instalada |
|---|---|
| Embeddings | `OllamaEmbeddingsProvider` |
| LLM | `Ollama` |
| Splitter | `DelimiterTextSplitter` |
| Store | `MemoryVectorStore` |
| Retrieval | `SimilarityRetrieval` |
| RAG | `RAG` |

O corpus Neuron � reconstru�do de `examples/documents` em mem�ria a cada execu��o.

| Vari�vel pr�pria | Padr�o |
|---|---|
| `NEURON_OLLAMA_URL` | `http://127.0.0.1:11434/api` |
| `NEURON_EMBEDDING_MODEL` | `bge-m3` |
| `NEURON_LANGUAGE_MODEL` | `qwen3:8b` |
| `NEURON_TOP_K` | `10` |
| `NEURON_MAXIMUM_DISTANCE` | `0.60` |
| `NEURON_RERANKER` | `none`, `localai`, `cohere` ou `jina` |
| `NEURON_RERANKER_TOP_N` | `1` |
| `NEURON_RERANKER_MODEL` | padr�o do provider escolhido |
| `NEURON_RERANKER_KEY` | obrigat�ria para Cohere e Jina |
| `NEURON_RERANKER_URL` | `http://127.0.0.1:8080` para LocalAI |
| `NEURON_CHUNK_SIZE` | `1000` |
| `NEURON_WORD_OVERLAP` | `25` |
| `NEURON_DOCUMENTS_PATH` | `examples/documents` |

> [!WARNING]
> `MemoryVectorStore` n�o filtra tenant, collection, status ou EmbeddingSpace. Use esses exemplos apenas para compara��o local com um corpus controlado.

O score do Neuron � similaridade. A busca imprime tamb�m `1 - score` como dist�ncia. No RAG, `FixedThresholdPostProcessor` usa `1 - NEURON_MAXIMUM_DISTANCE` como similaridade m�nima.

Essa compara��o avalia qualidade e ergonomia, mas n�o � um benchmark cient�fico: stores, splitters, prompts e ciclos de persist�ncia s�o diferentes.

## Melhor pipeline nativo

~~~text
topK=10
   |
FixedThreshold
   |
LocalAI, Cohere ou Jina reranker
   |
topN=1
~~~

Exemplo com Jina:

~~~bash
export NEURON_RERANKER=jina
export NEURON_RERANKER_KEY=sua-chave
export NEURON_RERANKER_TOP_N=1
php examples/neuron-simple-rag.php
~~~

O reranker LocalAI exige um servidor LocalAI com endpoint `/v1/rerank`; o Ollama n�o fornece esse endpoint. Quando `NEURON_RERANKER=none`, o exemplo executa apenas topK e threshold.

Se nenhuma fonte sobreviver ao threshold ou reranker, o exemplo encerra antes de `RAG::chat()` e imprime N�o h� evid�ncias suficientes no corpus. Assim o modelo n�o responde usando apenas conhecimento interno.

## PostgreSQL existente

O Neuron 3.16.1 n�o inclui um adapter nativo para PostgreSQL/pgvector. Por isso os exemplos n�o reutilizam a tabela do ContextEngine: isso exigiria escrever um `VectorStoreInterface` customizado ou importar o `PgVectorStore` da outra biblioteca, contaminando a compara��o.

O Ollama local � reutilizado diretamente pelos providers nativos do Neuron. Para os vetores, o exemplo mant�m `MemoryVectorStore`, que pertence ao pr�prio framework.
