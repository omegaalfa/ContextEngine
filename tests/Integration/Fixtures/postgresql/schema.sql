CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL,
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}',
    embedding vector(3) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 3),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    CONSTRAINT context_chunks_identity PRIMARY KEY (
        tenant_id,
        collection,
        chunk_id,
        embedding_space_fingerprint
    )
);

CREATE INDEX IF NOT EXISTS context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, embedding_space_fingerprint);
