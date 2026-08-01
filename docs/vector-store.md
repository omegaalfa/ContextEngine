# 🧭 Vector Store e PgVectorStore

> Persistência vetorial multi-tenant, versionada e com operações de manutenção explicitamente delimitadas.

O `VectorStore` é a porta entre o ContextEngine e qualquer mecanismo de persistência vetorial. A engine depende desse contrato para gravar chunks, buscar contexto e remover conhecimento antigo sem conhecer PostgreSQL, SQL, PDO ou QueryBuilder.

```text
                    ┌──────────────────────────┐
IngestionPipeline ─▶│                          │─▶ storeBatch()
Retriever ─────────▶│       VectorStore        │─▶ search()
Aplicação ─────────▶│                          │─▶ deleteChunk()
                    │                          │─▶ deleteDocument()
                    └──────────────────────────┘─▶ clearCollection()
                                  │
                                  ▼
                    PgVectorStore + QueryBuilder
                                  │
                                  ▼
                      PostgreSQL + pgvector
```

O pacote inclui `PgVectorStore`. Outros bancos podem ser integrados implementando o mesmo contrato e preservando suas garantias.

---

## ✨ Capacidades

| Operação | Responsabilidade | Escopo mínimo obrigatório |
|---|---|---|
| `storeBatch()` | Gravar ou atualizar chunks vetorizados atomicamente | tenant + collection + espaço do próprio lote |
| `search()` | Encontrar chunks semanticamente próximos | tenant + status + espaço; collection quando definida |
| `deleteChunk()` | Remover uma versão exata de um chunk | tenant + collection + chunk + espaço |
| `deleteDocument()` | Remover um documento em um espaço ou em todos | tenant + collection + documento |
| `clearCollection()` | Esvaziar uma collection do tenant | tenant + collection |

Não existe operação para apagar a tabela inteira ou todos os tenants.

---

## 🧱 Identidade persistida

Cada vetor é identificado por:

```text
tenant_id
  + collection
  + chunk_id
  + embedding_space_fingerprint
```

Essa identidade é a chave primária da fixture oficial:

```sql
PRIMARY KEY (
    tenant_id,
    collection,
    chunk_id,
    embedding_space_fingerprint
)
```

Consequências práticas:

- reingerir o mesmo chunk no mesmo espaço atualiza a linha;
- outro tenant cria uma linha independente;
- outra collection cria uma linha independente;
- outro modelo, dimensão, provider ou configuração cria outra versão;
- nenhuma API depende de `BIGSERIAL`, sequence ou ID técnico.

```text
chunk “política-1”
├── tenant-a / docs / Ollama BGE-M3 rev. 1
├── tenant-a / docs / Ollama BGE-M3 rev. 2
└── tenant-b / docs / Ollama BGE-M3 rev. 1
```

As três linhas podem coexistir e nunca participam da mesma busca vetorial.

---

## 🔌 Criando o PgVectorStore

Este exemplo usa o PostgreSQL publicado pelo `docker-compose.yml` do projeto:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

$settings = new DatabaseSettings(
    driver: 'pgsql',
    host: '127.0.0.1',
    database: 'context_engine',
    port: 54339,
    username: 'context_engine',
    password: 'context_engine',
);

$connection = new PDOConnection($settings);
$queryBuilder = new QueryBuilder($connection);
$store = new PgVectorStore($queryBuilder);
```

Em produção, leia credenciais do ambiente ou de um secret manager. Não registre senha, DSN ou payloads sensíveis em logs.

O construtor também aceita `PgVectorSchema` para mapear nomes confiáveis de tabela e colunas. Identificadores são validados por uma allowlist; valores continuam parametrizados pelo QueryBuilder.

---

## 📥 Gravando embeddings

Normalmente `IngestionPipeline` cria os `EmbeddedChunk`. O exemplo abaixo mostra a operação direta para deixar o contrato explícito:

```php
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

$space = new EmbeddingSpace(
    provider: 'ollama',
    model: 'bge-m3',
    dimensions: 1024,
    revision: '1',
);

$chunk = new Chunk(
    id: 'politica-reembolso-0',
    documentId: 'politica-reembolso',
    tenantId: 'empresa-a',
    content: 'O reembolso deve ser solicitado em até 30 dias.',
    position: 0,
    metadata: ['source' => 'politica-reembolso.txt'],
    collection: 'financeiro',
    status: 'active',
);

$values = array_fill(0, 1024, 0.0);
$values[0] = 1.0;

$store->storeBatch([
    new EmbeddedChunk($chunk, new Embedding($values, $space)),
]);
```

### Garantias do batch

`storeBatch()`:

- aceita `list<EmbeddedChunk>`;
- trata `[]` como no-op;
- rejeita itens inválidos;
- rejeita mistura de tenants;
- rejeita mistura de collections;
- rejeita mistura de espaços vetoriais;
- executa um único `INSERT ... ON CONFLICT`;
- deve ser atômico: se uma linha falhar, nenhuma linha daquele batch permanece gravada.

Fluxo interno:

```text
EmbeddedChunk[]
      │
      ├── validar tipos e escopo homogêneo
      ├── converter Embedding → pgvector Vector
      ▼
insertBatch(...)
      ▼
onConflict(tenant, collection, chunk, fingerprint)
      ▼
doUpdate(content, metadata, status, embedding)
      ▼
execute()
```

A atomicidade vale dentro de cada batch. A ingestão completa permanece incremental: batches persistidos antes de uma falha continuam duráveis e aparecem no relatório parcial.

---

## 🔎 Busca por similaridade

```php
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;

$questionValues = array_fill(0, 1024, 0.0);
$questionValues[0] = 1.0;

$results = $store->search(new VectorSearchQuery(
    tenantId: 'empresa-a',
    embedding: new Embedding($questionValues, $space),
    policy: new RetrievalPolicy(
        limit: 5,
        metric: VectorMetric::COSINE,
        maximumDistance: 0.45,
    ),
    collection: 'financeiro',
    status: 'active',
));

foreach ($results as $result) {
    printf(
        "chunk=%s documento=%s distância=%.4f\n",
        $result->chunk->id,
        $result->chunk->documentId,
        $result->distance,
    );
}
```

Antes do `LIMIT`, o SQL filtra:

```text
tenant
+ collection, quando definida
+ status
+ provider
+ model
+ dimensions
+ revision
+ embedding-space fingerprint
```

Vetores incompatíveis não são carregados para descarte posterior em PHP. A fixture inclui HNSW para cosseno; outra métrica pode exigir outro índice para manter desempenho.

---

## 🗑️ Removendo um chunk

Um chunk pode existir em vários espaços. Por isso `deleteChunk()` exige a identidade vetorial completa:

```php
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;

$removed = $store->deleteChunk(new ChunkDeleteQuery(
    tenantId: 'empresa-a',
    collection: 'financeiro',
    chunkId: 'politica-reembolso-0',
    space: $space,
));

printf("%d versão(ões) removida(s).\n", $removed);
```

Essa chamada não remove:

- o mesmo `chunkId` de outro tenant;
- o mesmo `chunkId` de outra collection;
- o mesmo `chunkId` vetorizado em outro espaço.

---

## 📄 Removendo um documento

### Somente uma versão vetorial

```php
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

$removed = $store->deleteDocument(new DocumentDeleteQuery(
    tenantId: 'empresa-a',
    collection: 'financeiro',
    documentId: 'politica-reembolso',
    space: $space,
));
```

Todos os chunks desse documento no espaço informado são removidos.

### Todas as versões vetoriais

```php
$removed = $store->deleteDocument(new DocumentDeleteQuery(
    tenantId: 'empresa-a',
    collection: 'financeiro',
    documentId: 'politica-reembolso',
));
```

Omitir `space` é uma decisão explícita: remove todas as versões do documento, mas continua limitado ao tenant e à collection.

| `space` | Comportamento |
|---|---|
| `EmbeddingSpace` informado | remove somente aquela versão |
| `null`/omitido | remove todas as versões do documento no escopo |

---

## 🧹 Esvaziando uma collection

```php
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;

$removed = $store->clearCollection(new CollectionDeleteQuery(
    tenantId: 'empresa-a',
    collection: 'base-temporaria',
));
```

Essa operação remove todos os documentos e espaços da collection indicada, mas nunca alcança:

- outra collection do mesmo tenant;
- uma collection de mesmo nome pertencente a outro tenant.

Use-a somente depois de autorização explícita da aplicação. O ContextEngine aplica isolamento estrutural; autenticação, autorização, confirmação de usuário e trilha de auditoria pertencem ao sistema consumidor.

---

## 🛡️ Matriz de segurança

| Operação | Tenant | Collection | Documento/chunk | Espaço |
|---|:---:|:---:|:---:|:---:|
| `search()` | obrigatório | opcional/configurado | — | obrigatório |
| `deleteChunk()` | obrigatório | obrigatório | chunk obrigatório | obrigatório |
| `deleteDocument()` | obrigatório | obrigatório | documento obrigatório | opcional |
| `clearCollection()` | obrigatório | obrigatório | — | todos |

Todos os valores vazios são rejeitados durante a construção da query, antes de qualquer SQL.

---

## ⚠️ Erros e comportamento operacional

- exclusão sem correspondência retorna `0`; não é erro;
- falhas do banco são propagadas pelo adapter;
- as operações não abrem transações durante chamadas HTTP;
- excluir vetores não invalida automaticamente caches PSR-16 de embeddings ou respostas;
- nenhuma exclusão física é executada automaticamente durante a ingestão;
- reingerir um documento não remove chunks antigos que deixaram de ser produzidos.

O último ponto é importante: o UPSERT torna identidades existentes idempotentes, mas substituição atômica de documento e coleta de chunks obsoletos continuam sendo políticas da aplicação/roadmap.

---

## 🧩 Implementando outro VectorStore

```php
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

final class CustomVectorStore implements VectorStore
{
    public function storeBatch(array $chunks): void
    {
        // Persistir o batch inteiro atomicamente.
    }

    public function search(VectorSearchQuery $query): array
    {
        // Filtrar tenant, collection/status e espaço antes do limite.
        return [];
    }

    public function deleteChunk(ChunkDeleteQuery $query): int
    {
        return 0;
    }

    public function deleteDocument(DocumentDeleteQuery $query): int
    {
        return 0;
    }

    public function clearCollection(CollectionDeleteQuery $query): int
    {
        return 0;
    }
}
```

Uma implementação compatível deve preservar:

1. atomicidade interna de `storeBatch()`;
2. ordem e cardinalidade dos resultados quando aplicável;
3. isolamento obrigatório por tenant;
4. compatibilidade completa de `EmbeddingSpace`;
5. exclusões limitadas pelos objetos de escopo;
6. retorno correto da quantidade removida;
7. ausência de tipos específicos do banco nos contratos públicos.

---

## ✅ Checklist de integração

```text
[ ] Schema provisionado externamente
[ ] Dimensão da coluna compatível com o provider
[ ] Índice vetorial compatível com a métrica usada
[ ] Tenant e collection definidos em toda gravação
[ ] Reingestão idempotente testada
[ ] Busca entre espaços incompatíveis impedida
[ ] Exclusão de chunk testada em espaços diferentes
[ ] Exclusão de documento testada entre tenants
[ ] clearCollection protegido por autorização da aplicação
[ ] Backup e política de retenção definidos
```

Veja também:

- [Schema PostgreSQL](database-schema.md)
- [Ingestão](ingestion.md)
- [Retrieval](retrieval.md)
- [Arquitetura](architecture.md)
- [Integração Docker](docker-integration.md)
