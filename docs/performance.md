# Performance

## Controles

- `chunkSize`: chunks maiores aumentam contexto por resultado e custo de embedding.
- `overlap`: melhora continuidade, mas duplica tokens e armazenamento.
- `batchSize`: reduz overhead de requests, mas eleva payload/memória.
- `concurrency`: aumenta throughput até limites de rede/provider.
- `dimensions`: afeta memória, disco, índice e custo.
- `RetrievalPolicy::limit`: controla top-k e tamanho do prompt.
- cache: evita recomputação, com custo de serialização/backend.

Persistência é serial por conexão, deliberadamente fora das chamadas HTTP. Uma tabela única duplica conteúdo por espaço vetorial. Para muitos espaços, considere em evolução futura separar chunks e embeddings.

Escolha o índice pgvector/operator class compatível com a métrica e volume. Faça `EXPLAIN` no ambiente real e avalie recall/latência; o pacote não publica benchmarks nem recomenda números universais.

## Resultados futuros

Registre versão do PHP/PostgreSQL/pgvector, hardware, dimensões, corpus, chunk/batch/window, índice, recall e percentis de latência. Não compare apenas throughput bruto.
