<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\NeighborAwareVectorStore;
use Omegaalfa\ContextEngine\Contract\VersionedVectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\InvalidEmbeddingException;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Ingestion\IngestionState;
use Omegaalfa\ContextEngine\Retrieval\NeighborSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric as ContextVectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\QueryBuilder\Enums\OrderDirection;
use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use Omegaalfa\QueryBuilder\QueryBuilder;

final readonly class PgVectorStore implements VersionedVectorStore, NeighborAwareVectorStore
{
    /**
     * @param QueryBuilder $query
     * @param PgVectorSchema $schema
     */
    public function __construct(private QueryBuilder $query, private PgVectorSchema $schema = new PgVectorSchema()) {}

    /**
     * @param list<EmbeddedChunk> $chunks
     * @return void
     * @throws JsonException
     * @throws DatabaseException
     * @throws QueryException
     * @throws UnsupportedDatabaseFeatureException
     */
    public function storeBatch(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }
        foreach ($chunks as $index => $item) {
            // Runtime validation protects callers because PHP arrays do not enforce PHPDoc generics.
            /** @phpstan-ignore instanceof.alwaysTrue */
            if (!$item instanceof EmbeddedChunk) {
                throw new InvalidArgumentException(sprintf(
                    'Expected EmbeddedChunk at index %d, received %s.',
                    $index,
                    get_debug_type($item),
                ));
            }
        }
        $firstItem = $chunks[0];
        $first = $firstItem->embedding;
        $tenantId = $firstItem->chunk->tenantId;
        $collection = $firstItem->chunk->collection;
        $rows = [];
        foreach ($chunks as $item) {
            if ($item->embedding->space->fingerprint() !== $first->space->fingerprint()) {
                throw new InvalidEmbeddingException('A batch cannot mix vector spaces.');
            }
            $chunk = $item->chunk;
            if ($chunk->tenantId !== $tenantId || $chunk->collection !== $collection) {
                throw new InvalidArgumentException('A batch cannot mix tenants or collections.');
            }
            $directVersion = hash('sha256', implode("\0", [$chunk->tenantId, $chunk->collection, $chunk->documentId, $item->embedding->space->fingerprint(), 'direct-store']));
            $rows[] = [$this->schema->chunkId => $chunk->id, $this->schema->documentId => $chunk->documentId, $this->schema->documentVersion => $directVersion, $this->schema->ingestionState => IngestionState::ACTIVE->value, $this->schema->tenantId => $chunk->tenantId, $this->schema->collection => $chunk->collection, $this->schema->status => $chunk->status, $this->schema->content => $chunk->content, $this->schema->position => $chunk->position, $this->schema->metadata => json_encode($chunk->metadata, JSON_THROW_ON_ERROR), $this->schema->embedding => new Vector($item->embedding->values, $item->embedding->dimensions()), $this->schema->embeddingProvider => $item->embedding->space->provider, $this->schema->embeddingModel => $item->embedding->space->model, $this->schema->embeddingDimensions => $item->embedding->space->dimensions, $this->schema->embeddingRevision => $item->embedding->space->revision, $this->schema->embeddingFingerprint => $item->embedding->space->fingerprint()];
        }
        $this->query->insertBatch($this->schema->table, $rows)->onConflict([$this->schema->tenantId, $this->schema->collection, $this->schema->chunkId, $this->schema->embeddingFingerprint, $this->schema->documentVersion])->doUpdate([$this->schema->content, $this->schema->metadata, $this->schema->status, $this->schema->embedding]);
        $this->query->execute();
    }

    public function beginVersion(DocumentVersion $version): void
    {
        $this->query->delete($this->schema->table)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $version->document->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $version->document->collection)
            ->where($this->schema->documentId, SqlOperator::EQUALS, $version->document->id)
            ->where($this->schema->documentVersion, SqlOperator::EQUALS, $version->id)
            ->where($this->schema->embeddingFingerprint, SqlOperator::EQUALS, $version->space->fingerprint())
            ->where($this->schema->ingestionState, SqlOperator::NOT_EQUALS, IngestionState::ACTIVE->value)
            ->execute();
    }

    public function stageBatch(DocumentVersion $version, array $chunks): void
    {
        if ($chunks === []) {
            return;
        }
        $rows = $this->rows($chunks, $version);
        $this->query->insertBatch($this->schema->table, $rows)
            ->onConflict([$this->schema->tenantId, $this->schema->collection, $this->schema->chunkId, $this->schema->embeddingFingerprint, $this->schema->documentVersion])
            ->doUpdate([$this->schema->content, $this->schema->metadata, $this->schema->status, $this->schema->embedding]);
        $this->query->execute();
    }

    public function activateVersion(DocumentVersion $version): void
    {
        $table = $this->schema->table;
        $this->query->transactional(function (QueryBuilder $query) use ($version, $table): void {
            $scope = [$version->document->tenantId, $version->document->collection, $version->document->id, $version->space->fingerprint()];
            $query->raw("UPDATE {$table} SET {$this->schema->ingestionState} = ? WHERE {$this->schema->tenantId} = ? AND {$this->schema->collection} = ? AND {$this->schema->documentId} = ? AND {$this->schema->embeddingFingerprint} = ? AND {$this->schema->ingestionState} = ? AND {$this->schema->documentVersion} <> ?", [IngestionState::SUPERSEDED->value, ...$scope, IngestionState::ACTIVE->value, $version->id])->execute();
            $activated = $query->raw("UPDATE {$table} SET {$this->schema->ingestionState} = ? WHERE {$this->schema->tenantId} = ? AND {$this->schema->collection} = ? AND {$this->schema->documentId} = ? AND {$this->schema->embeddingFingerprint} = ? AND {$this->schema->documentVersion} = ? AND {$this->schema->ingestionState} IN (?, ?)", [IngestionState::ACTIVE->value, ...$scope, $version->id, IngestionState::STAGED->value, IngestionState::ACTIVE->value])->execute()->count;
            if ($activated < 1) {
                throw new LogicException('Cannot activate a document version without staged chunks.');
            }
        });
    }

    public function failVersion(DocumentVersion $version): void
    {
        $this->query->update($this->schema->table, [$this->schema->ingestionState => IngestionState::FAILED->value])
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $version->document->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $version->document->collection)
            ->where($this->schema->documentId, SqlOperator::EQUALS, $version->document->id)
            ->where($this->schema->documentVersion, SqlOperator::EQUALS, $version->id)
            ->where($this->schema->embeddingFingerprint, SqlOperator::EQUALS, $version->space->fingerprint())
            ->where($this->schema->ingestionState, SqlOperator::EQUALS, IngestionState::STAGED->value)
            ->execute();
    }

    /**
     * @param VectorSearchQuery $query
     * @return array|VectorSearchResult[]
     * @throws JsonException
     * @throws DatabaseException
     * @throws QueryException
     */
    public function search(VectorSearchQuery $query): array
    {
        $space = $query->embedding->space;
        $fields = [$this->schema->chunkId, $this->schema->documentId, $this->schema->documentVersion, $this->schema->tenantId, $this->schema->collection, $this->schema->status, $this->schema->content, $this->schema->position, $this->schema->metadata];
        $metric = match ($query->policy->metric) {
            ContextVectorMetric::L2 => VectorMetric::L2,
            ContextVectorMetric::INNER_PRODUCT => VectorMetric::INNER_PRODUCT,
            ContextVectorMetric::COSINE => VectorMetric::COSINE,
            ContextVectorMetric::L1 => VectorMetric::L1,
        };
        $operation = $this->query->select($this->schema->table, $fields)->nearestNeighbors($this->schema->embedding, new Vector($query->embedding->values), $metric, 'distance', $space->dimensions)->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)->where($this->schema->status, SqlOperator::EQUALS, $query->status)->where($this->schema->ingestionState, SqlOperator::EQUALS, IngestionState::ACTIVE->value)->where($this->schema->embeddingProvider, SqlOperator::EQUALS, $space->provider)->where($this->schema->embeddingModel, SqlOperator::EQUALS, $space->model)->where($this->schema->embeddingDimensions, SqlOperator::EQUALS, $space->dimensions)->where($this->schema->embeddingRevision, SqlOperator::EQUALS, $space->revision)->where($this->schema->embeddingFingerprint, SqlOperator::EQUALS, $space->fingerprint());
        if ($query->collection !== null) {
            $operation->where($this->schema->collection, SqlOperator::EQUALS, $query->collection);
        }
        $operation->limit($query->policy->limit);
        $result = $this->query->execute(false);
        $found = [];
        foreach ($result->data as $row) {
            $distance = (float)$row['distance'];
            if ($query->policy->maximumDistance !== null && $distance > $query->policy->maximumDistance) {
                continue;
            }
            $decoded = json_decode((string)$row[$this->schema->metadata], true, flags: JSON_THROW_ON_ERROR);
            $metadata = [];
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_scalar($value) || $value === null)) {
                        $metadata[$key] = $value;
                    }
                }
            }
            $chunk = new Chunk((string)$row[$this->schema->chunkId], (string)$row[$this->schema->documentId], (string)$row[$this->schema->tenantId], (string)$row[$this->schema->content], (int)$row[$this->schema->position], $metadata, (string)$row[$this->schema->collection], (string)$row[$this->schema->status]);
            $found[] = new VectorSearchResult($chunk, $distance, (string)$row[$this->schema->documentVersion]);
        }
        return $found;
    }

    /** @return list<Chunk> */
    public function neighbors(NeighborSearchQuery $query): array
    {
        $from = max(0, $query->position - $query->before);
        $to = $query->position + $query->after;
        $fields = [
            $this->schema->chunkId,
            $this->schema->documentId,
            $this->schema->tenantId,
            $this->schema->collection,
            $this->schema->status,
            $this->schema->content,
            $this->schema->position,
            $this->schema->metadata,
        ];
        $operation = $this->query->select($this->schema->table, $fields)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $query->collection)
            ->where($this->schema->status, SqlOperator::EQUALS, $query->status)
            ->where($this->schema->documentId, SqlOperator::EQUALS, $query->documentId)
            ->where($this->schema->documentVersion, SqlOperator::EQUALS, $query->documentVersion)
            ->where($this->schema->ingestionState, SqlOperator::EQUALS, IngestionState::ACTIVE->value)
            ->where($this->schema->position, SqlOperator::GREATER_THAN_OR_EQUALS, $from)
            ->where($this->schema->position, SqlOperator::LESS_THAN_OR_EQUALS, $to)
            ->orderBy($this->schema->position, OrderDirection::ASC)
            ->limit($query->before + $query->after + 1);
        $this->applySpace($operation, $query->space);
        $result = $operation->execute(false);
        $neighbors = [];
        foreach ($result->data as $row) {
            $decoded = json_decode((string)$row[$this->schema->metadata], true, flags: JSON_THROW_ON_ERROR);
            $metadata = [];
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_scalar($value) || $value === null)) {
                        $metadata[$key] = $value;
                    }
                }
            }
            $neighbors[] = new Chunk(
                (string)$row[$this->schema->chunkId],
                (string)$row[$this->schema->documentId],
                (string)$row[$this->schema->tenantId],
                (string)$row[$this->schema->content],
                (int)$row[$this->schema->position],
                $metadata,
                (string)$row[$this->schema->collection],
                (string)$row[$this->schema->status],
            );
        }
        return $neighbors;
    }

    /**
     * @param ChunkDeleteQuery $query
     * @return int
     * @throws DatabaseException
     * @throws QueryException
     */
    public function deleteChunk(ChunkDeleteQuery $query): int
    {
        $operation = $this->query->delete($this->schema->table)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $query->collection)
            ->where($this->schema->chunkId, SqlOperator::EQUALS, $query->chunkId);
        $this->applySpace($operation, $query->space);

        return $operation->execute()->count;
    }

    /**
     * @param DocumentDeleteQuery $query
     * @return int
     * @throws DatabaseException
     * @throws QueryException
     */
    public function deleteDocument(DocumentDeleteQuery $query): int
    {
        $operation = $this->query->delete($this->schema->table)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId);
        if ($query->collection !== null) {
            $operation->where($this->schema->collection, SqlOperator::EQUALS, $query->collection);
        }
        if ($query->documentId !== null) {
            $operation->where($this->schema->documentId, SqlOperator::EQUALS, $query->documentId);
        }
        if ($query->space !== null) {
            $this->applySpace($operation, $query->space);
        }

        return $operation->execute()->count;
    }

    /**
     * @param CollectionDeleteQuery $query
     * @return int
     * @throws DatabaseException
     * @throws QueryException
     */
    public function clearCollection(CollectionDeleteQuery $query): int
    {
        return $this->query->delete($this->schema->table)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $query->collection)
            ->execute()
            ->count;
    }

    /**
     * @param QueryBuilder $operation
     * @param EmbeddingSpace $space
     * @return void
     * @throws QueryException
     */
    private function applySpace(QueryBuilder $operation, EmbeddingSpace $space): void
    {
        $operation
            ->where($this->schema->embeddingProvider, SqlOperator::EQUALS, $space->provider)
            ->where($this->schema->embeddingModel, SqlOperator::EQUALS, $space->model)
            ->where($this->schema->embeddingDimensions, SqlOperator::EQUALS, $space->dimensions)
            ->where($this->schema->embeddingRevision, SqlOperator::EQUALS, $space->revision)
            ->where($this->schema->embeddingFingerprint, SqlOperator::EQUALS, $space->fingerprint());
    }

    /**
     * @param list<EmbeddedChunk> $chunks
     * @return list<array<string, mixed>>
     */
    private function rows(array $chunks, DocumentVersion $version): array
    {
        $rows = [];
        foreach ($chunks as $item) {
            if ($item->chunk->documentId !== $version->document->id || $item->chunk->tenantId !== $version->document->tenantId || $item->chunk->collection !== $version->document->collection || $item->embedding->space->fingerprint() !== $version->space->fingerprint()) {
                throw new InvalidArgumentException('Staged chunks must match the document version scope.');
            }
            $chunk = $item->chunk;
            $rows[] = [$this->schema->chunkId => $chunk->id, $this->schema->documentId => $chunk->documentId, $this->schema->documentVersion => $version->id, $this->schema->ingestionState => IngestionState::STAGED->value, $this->schema->tenantId => $chunk->tenantId, $this->schema->collection => $chunk->collection, $this->schema->status => $chunk->status, $this->schema->content => $chunk->content, $this->schema->position => $chunk->position, $this->schema->metadata => json_encode($chunk->metadata, JSON_THROW_ON_ERROR), $this->schema->embedding => new Vector($item->embedding->values, $item->embedding->dimensions()), $this->schema->embeddingProvider => $item->embedding->space->provider, $this->schema->embeddingModel => $item->embedding->space->model, $this->schema->embeddingDimensions => $item->embedding->space->dimensions, $this->schema->embeddingRevision => $item->embedding->space->revision, $this->schema->embeddingFingerprint => $item->embedding->space->fingerprint()];
        }
        return $rows;
    }
}
