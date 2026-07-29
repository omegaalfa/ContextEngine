# Vector store e PgVectorStore

`PgVectorStore(QueryBuilder $query, PgVectorSchema $schema = new PgVectorSchema())` implementa `VectorStore`.

## Persistência

`storeBatch(non-empty-list<EmbeddedChunk>): void` valida um único espaço por batch, transforma vetores em `QueryBuilder\PostgreSQL\PgVector\Vector` e executa:

```text
insertBatch → onConflict([
  tenant_id, collection, chunk_id, embedding_space_fingerprint
]) → doUpdate([content, metadata, status, embedding]) → execute
```

Não usa SQL vetorial manual, `RETURNING`, sequence ou `lastInsertId()` no fluxo. Uma revisão/espaço diferente cria outra linha; identidade igual atualiza dados mutáveis.

## Busca

`search(VectorSearchQuery $query): list<VectorSearchResult>` usa `nearestNeighbors()` e aplica antes de `limit()`:

- tenant e status;
- collection, se não for `null`;
- provider, model, dimensions, revision e fingerprint.

O enum público `ContextEngine\Retrieval\VectorMetric` é convertido dentro do store para o enum do QueryBuilder. Esse tipo de infraestrutura não vaza para domínio/contratos.

`PgVectorSchema` permite nomes confiáveis customizados e rejeita identificadores fora de `[A-Za-z_][A-Za-z0-9_]*`.
