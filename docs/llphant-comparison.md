<div align="center">

# 🐘 ContextEngine × LLPhant

### Dois pipelines independentes, o mesmo Ollama e o mesmo corpus

**Busca sem LLM · RAG fundamentado · tempo · memória · fontes**

</div>

> [!IMPORTANT]
> Estes exemplos servem para experimentar a qualidade e a ergonomia do LLPhant. Eles não importam classes do ContextEngine e não representam um benchmark científico.

## 🚀 Execução rápida

O pacote oficial `theodo-group/llphant` está apenas em `require-dev`.

```bash
composer install

export LLPHANT_OLLAMA_URL=http://127.0.0.1:11434
export LLPHANT_EMBEDDING_MODEL=bge-m3
export LLPHANT_LANGUAGE_MODEL=qwen3:8b

php examples/llphant-simple-search.php
php examples/llphant-simple-rag.php
```

Você também pode passar a pergunta como argumento:

```bash
php examples/llphant-simple-search.php "Como funciona uma árvore binária ótima?"
php examples/llphant-simple-rag.php "Como funciona uma árvore binária ótima?"
```

Os scripts usam `getenv()` e não carregam o `.env` automaticamente. Variáveis exportadas pelo terminal, Docker ou CI são a fonte de configuração.

## 🧭 O que acontece

```text
examples/documents
       │
       ▼
 FileDataReader
       │
       ▼
DocumentSplitter ── tamanho 1000 / overlap 25 palavras
       │
       ▼
OllamaEmbeddingGenerator ── bge-m3
       │
       ▼
MemoryVectorStore ── CosineDistance
       │
       ▼
 topK=10 ── distância ≤ 0.60 ── limite final=1
       │
       ├── busca: imprime evidências
       └── RAG: QuestionAnswering + OllamaChat
```

O exemplo de busca termina depois do retrieval. O exemplo RAG usa os documentos aprovados para construir um `MemoryVectorStore` reduzido e entrega esse store ao `QuestionAnswering` nativo do LLPhant.

> [!CAUTION]
> Quando nenhuma evidência passa pelo threshold, o exemplo encerra **antes** de chamar `OllamaChat`. Isso evita comparar um RAG fundamentado com uma resposta baseada somente na memória interna do modelo.

## 🧩 APIs nativas utilizadas

| Responsabilidade | LLPhant 1.0.1 |
|---|---|
| Ler arquivos | `FileDataReader` |
| Dividir texto | `DocumentSplitter` |
| Gerar embeddings | `OllamaEmbeddingGenerator` |
| Medir relevância | `CosineDistance` |
| Armazenar e buscar | `MemoryVectorStore` |
| Gerar a resposta RAG | `QuestionAnswering` |
| Conversar com Ollama | `OllamaChat` |

O LLPhant não expõe o score junto ao `Document` retornado pelo `MemoryVectorStore`. Por isso o exemplo mede novamente a distância com o `CosineDistance` da própria biblioteca. Não há classe do ContextEngine nesse cálculo.

## ⚙️ Configuração

| Variável | Padrão | Efeito |
|---|---:|---|
| `LLPHANT_OLLAMA_URL` | `http://127.0.0.1:11434` | Servidor Ollama; `/api/` é normalizado pelo exemplo |
| `LLPHANT_EMBEDDING_MODEL` | `bge-m3` | Modelo usado para documentos e pergunta |
| `LLPHANT_LANGUAGE_MODEL` | `qwen3:8b` | Modelo chamado somente no exemplo RAG |
| `LLPHANT_OLLAMA_TIMEOUT` | `300` | Timeout HTTP em segundos |
| `LLPHANT_DOCUMENTS_PATH` | `examples/documents` | Corpus reconstruído em memória |
| `LLPHANT_TOP_K` | `10` | Candidatos iniciais |
| `LLPHANT_MAXIMUM_DISTANCE` | `0.60` | Maior distância cosseno aceita |
| `LLPHANT_FINAL_LIMIT` | `1` | Máximo de evidências entregues ao RAG |
| `LLPHANT_CHUNK_SIZE` | `1000` | Tamanho máximo aproximado do chunk |
| `LLPHANT_WORD_OVERLAP` | `25` | Palavras repetidas entre chunks |

## 📊 Leitura dos resultados

Distância menor significa maior proximidade semântica. Com `LLPHANT_MAXIMUM_DISTANCE=0.60`, um resultado `0.41` passa e um resultado `0.72` é descartado.

```text
#1 distância=0.416851 fonte=optimal-bst-python.txt chunk=2
```

Cada execução também informa tempo total e pico de memória. Execute mais de uma vez: o primeiro teste pode incluir o carregamento do modelo pelo Ollama.

## ⚖️ Limites da comparação

| LLPhant neste exemplo | ContextEngine |
|---|---|
| Corpus reprocessado em memória a cada execução | Vetores persistidos no PostgreSQL/pgvector |
| Sem tenant, collection, status ou espaço vetorial | Filtros e `EmbeddingSpace` fazem parte da consulta |
| Threshold aplicado pelo código do exemplo | Política integrada ao retrieval |
| Um store reduzido protege a chamada ao RAG | O pipeline bloqueia o LLM quando não há fontes |
| Não compartilha o banco já ingerido | Consulta a base persistida da aplicação |

LLPhant oferece outros vector stores, inclusive PostgreSQL via Doctrine, mas reutilizar diretamente a tabela específica do ContextEngine exigiria mapear outro schema e misturaria as duas arquiteturas. Para uma comparação limpa, este exemplo usa o `MemoryVectorStore` oficial.

## ✅ Resultado validado

Os dois arquivos foram executados contra Ollama local com LLPhant `1.0.1` e `bge-m3`. A busca encontrou `optimal-bst-python.txt`; o RAG chamou `qwen3:8b` somente depois de uma evidência passar pelo threshold.
