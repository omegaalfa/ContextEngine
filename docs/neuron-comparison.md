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
| `NEURON_TOP_K` | `5` |
| `NEURON_MAXIMUM_DISTANCE` | `0.45` |
| `NEURON_CHUNK_SIZE` | `1000` |
| `NEURON_WORD_OVERLAP` | `25` |
| `NEURON_DOCUMENTS_PATH` | `examples/documents` |

> [!WARNING]
> `MemoryVectorStore` n�o filtra tenant, collection, status ou EmbeddingSpace. Use esses exemplos apenas para compara��o local com um corpus controlado.

O score do Neuron � similaridade. A busca imprime tamb�m `1 - score` como dist�ncia. No RAG, `FixedThresholdPostProcessor` usa `1 - NEURON_MAXIMUM_DISTANCE` como similaridade m�nima.

Essa compara��o avalia qualidade e ergonomia, mas n�o � um benchmark cient�fico: stores, splitters, prompts e ciclos de persist�ncia s�o diferentes.

## PostgreSQL existente

O Neuron 3.16.1 n�o inclui um adapter nativo para PostgreSQL/pgvector. Por isso os exemplos n�o reutilizam a tabela do ContextEngine: isso exigiria escrever um `VectorStoreInterface` customizado ou importar o `PgVectorStore` da outra biblioteca, contaminando a compara��o.

O Ollama local � reutilizado diretamente pelos providers nativos do Neuron. Para os vetores, o exemplo mant�m `MemoryVectorStore`, que pertence ao pr�prio framework.
