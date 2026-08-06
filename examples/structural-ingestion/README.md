# Exemplos de ingestão estrutural

Esta pasta contém exemplos independentes e progressivos. Execute todos a partir da raiz do projeto.

## Pré-requisitos

Os exemplos `01`, `02` e `05` são locais: não usam banco, Ollama ou rede.

Os exemplos `03` e `04` usam a configuração do `.env` e precisam de:

- PostgreSQL com pgvector e schema do ContextEngine;
- Ollama acessível;
- modelo de embedding configurado e instalado;
- dependências do Composer instaladas.

Para iniciar a infraestrutura Docker fornecida pelo projeto:

```bash
docker compose up -d
```

## 1. Visualizar a árvore lógica

```bash
php examples/structural-ingestion/01-parse-document.php
```

Mostra qual parser foi selecionado e imprime headings, parágrafos, listas, citações, código e tabela na ordem original.

## 2. Gerar chunks sem persistir

```bash
php examples/structural-ingestion/02-build-chunks.php
```

O primeiro argumento altera o limite por caracteres:

```bash
php examples/structural-ingestion/02-build-chunks.php 300
php examples/structural-ingestion/02-build-chunks.php 800
```

## 3. Ingerir no vector store

```bash
php examples/structural-ingestion/03-ingest.php
```

O comando lê `documents/manual-pagamentos.md`, seleciona `MarkdownParser`, gera chunks estruturais, cria embeddings e ativa a versão no pgvector.

## 4. Buscar o conteúdo ingerido

Execute a ingestão antes da busca:

```bash
php examples/structural-ingestion/03-ingest.php
php examples/structural-ingestion/04-search.php
```

Também é possível informar a pergunta na linha de comando:

```bash
php examples/structural-ingestion/04-search.php "Qual cabeçalho HTTP autentica a API?"
php examples/structural-ingestion/04-search.php "O que significa o status 409?"
php examples/structural-ingestion/04-search.php "Como renovar um token de cartão expirado?"
```

Os resultados mostram distância vetorial, documento, posição, heading pai, caminho hierárquico e conteúdo.

## 5. Comparar estratégias

```bash
php examples/structural-ingestion/05-compare-strategies.php
```

Compara limites por caracteres, tokens estimados e quantidade de blocos sem persistir dados.

## Sequência completa

```bash
php examples/structural-ingestion/01-parse-document.php
php examples/structural-ingestion/02-build-chunks.php 500
php examples/structural-ingestion/05-compare-strategies.php
php examples/structural-ingestion/03-ingest.php
php examples/structural-ingestion/04-search.php "Como tratar ERR_PAYMENT_1047?"
```

Todos os exemplos usam o mesmo tenant e a mesma collection definidos por `CONTEXT_ENGINE_TENANT_ID` e `CONTEXT_ENGINE_COLLECTION`.

## Testando o livro em PDF

O arquivo `documents/Algoritimos e estrutura de dados em PHP.pdf` possui exemplos próprios.

Visualize as cinco primeiras páginas extraídas pelo Poppler:

```bash
php examples/structural-ingestion/06-preview-pdf.php 5
```

Inspecione a árvore lógica e os chunks das primeiras 20 páginas, sem embeddings ou banco:

```bash
php examples/structural-ingestion/09-inspect-pdf-structure.php 20 1200
```

Faça uma ingestão rápida das primeiras 20 páginas. Elas formam um único documento lógico; páginas são apenas metadata:

```bash
php examples/structural-ingestion/07-ingest-pdf.php 20 1200
```

Faça a ingestão das 270 páginas:

```bash
php examples/structural-ingestion/07-ingest-pdf.php all 1200
```

Pesquise apenas resultados marcados como pertencentes ao livro:

```bash
php examples/structural-ingestion/08-search-book.php "Como funciona uma lista encadeada em PHP?"
php examples/structural-ingestion/08-search-book.php "Explique o algoritmo de ordenação quicksort"
```

## Livro com a High-Level API

Os mesmos procedimentos podem ser executados sem compor `Bootstrap`, provider, executor, store ou retriever manualmente.

Ingestão completa:

```bash
php examples/structural-ingestion/11-high-level-ingest-pdf.php all 1200
```

Busca:

```bash
php examples/structural-ingestion/12-high-level-search-book.php "O que é notação Big O?"
php examples/structural-ingestion/12-high-level-search-book.php "Como funciona o quicksort?"
```

Envie a pergunta, o contexto recuperado e as fontes para o modelo de linguagem:

```bash
php examples/structural-ingestion/13-high-level-ask-book.php "Como funciona o quicksort?"
```

Esse exemplo usa `askWithDiagnostics()`, imprime a resposta da IA e lista os chunks realmente enviados ao modelo com páginas, headings e distâncias.
Como modelos locais podem levar mais de 30 segundos para gerar, o exemplo configura timeout de leitura de 180 segundos pela própria API fluente com `withLanguageModelFactory()`.
