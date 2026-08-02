# Schema PostgreSQL/pgvector

A biblioteca não executa este DDL. Provisione-o externamente:

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    document_version text NOT NULL,
    ingestion_state text NOT NULL DEFAULT 'staged'
        CHECK (ingestion_state IN ('staged', 'active', 'failed', 'superseded')),
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL,
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}',
    embedding vector(1024) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 1024),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    PRIMARY KEY (tenant_id, collection, chunk_id, embedding_space_fingerprint, document_version)
);

CREATE INDEX context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, ingestion_state, embedding_space_fingerprint);
```

Não há timestamps no schema atual porque o store não fornece esses valores. Adicioná-los exige defaults no banco ou evolução explícita do contrato/schema.

O schema fornecido está configurado para `OllamaEmbeddingProvider(model: 'bge-m3', dimensions: 1024)`. O BGE-M3 produz embeddings densos de 1024 dimensões. Misturar outra dimensão na mesma coluna `vector(n)` falha no banco; espaços diferentes com 1024 dimensões ainda podem coexistir pela identidade completa.

Para essa configuração, provider/model/dimensions/revision são `ollama`, `bge-m3`, `1024` e `1`. Não calcule o fingerprint concatenando esses textos manualmente: use `$provider->space()->fingerprint()`, pois `EmbeddingSpace` aplica a canonicalização determinística oficial e inclui `parameters`.

Além do índice de escopo, a fixture cria um índice por documento/posição e um HNSW com `vector_cosine_ops`. Este último acelera a métrica cosseno; outras métricas expostas pela API podem exigir índice correspondente conforme a carga.

A PK composta é maior que uma chave surrogate, mas elimina identidade técnica sem uso. `document_version` permite manter a versão ativa enquanto a próxima é ingerida. O retrieval sempre filtra `ingestion_state = 'active'`; versões `staged`, `failed` e `superseded` permanecem fora dos resultados.
