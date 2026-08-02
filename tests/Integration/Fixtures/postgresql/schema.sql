CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    document_version text NOT NULL,
    ingestion_state text NOT NULL DEFAULT 'staged' CHECK (ingestion_state IN ('staged', 'active', 'failed', 'superseded')),
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL DEFAULT 'active',
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    embedding vector(1024) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 1024),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    CONSTRAINT context_chunks_identity PRIMARY KEY (
        tenant_id,
        collection,
        chunk_id,
        embedding_space_fingerprint,
        document_version
    ),
    CONSTRAINT context_chunks_content_not_empty CHECK (btrim(content) <> ''),
    CONSTRAINT context_chunks_provider_not_empty CHECK (btrim(embedding_provider) <> ''),
    CONSTRAINT context_chunks_model_not_empty CHECK (btrim(embedding_model) <> '')
);

CREATE INDEX IF NOT EXISTS context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, ingestion_state, embedding_space_fingerprint);

CREATE INDEX IF NOT EXISTS context_chunks_document_position_idx ON context_chunks
    (tenant_id, collection, document_id, document_version, position);

CREATE INDEX IF NOT EXISTS context_chunks_embedding_hnsw_idx ON context_chunks
    USING hnsw (embedding vector_cosine_ops);
