# Schema PostgreSQL/pgvector

A biblioteca não executa este DDL. Provisione-o externamente:

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL,
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}',
    embedding vector(1536) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 1536),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    PRIMARY KEY (tenant_id, collection, chunk_id, embedding_space_fingerprint)
);

CREATE INDEX context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, embedding_space_fingerprint);
```

Não há timestamps no schema atual porque o store não fornece esses valores. Adicioná-los exige defaults no banco ou evolução explícita do contrato/schema.

Na fixture rápida, `vector(3)` e check `= 3` reduzem custo. Produção deve escolher uma dimensão única compatível, por exemplo `vector(384)`, `vector(768)`, `vector(1536)` ou `vector(3072)`, e repetir o mesmo número no check e `EmbeddingSpace`. Misturar dimensões na mesma coluna `vector(n)` falha no banco; espaços diferentes com a mesma dimensão podem coexistir.

A PK composta é maior que uma chave surrogate, mas elimina identidade técnica sem uso. O índice de scope duplica parcialmente tenant/collection/fingerprint porque seu padrão de acesso inclui status e omite chunk ID.
