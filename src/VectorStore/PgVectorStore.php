<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\VectorStore;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\InvalidEmbeddingException;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric as ContextVectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use Omegaalfa\QueryBuilder\QueryBuilder;

final readonly class PgVectorStore implements VectorStore
{
    /**
     * @param QueryBuilder $query
     * @param PgVectorSchema $schema
     */
    public function __construct(private QueryBuilder $query, private PgVectorSchema $schema = new PgVectorSchema())
    {
    }

    /**
     * @param list<EmbeddedChunk> $chunks
     * @return void
     * @throws JsonException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\DatabaseException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException
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
            $rows[] = [$this->schema->chunkId => $chunk->id, $this->schema->documentId => $chunk->documentId, $this->schema->tenantId => $chunk->tenantId, $this->schema->collection => $chunk->collection, $this->schema->status => $chunk->status, $this->schema->content => $chunk->content, $this->schema->position => $chunk->position, $this->schema->metadata => json_encode($chunk->metadata, JSON_THROW_ON_ERROR), $this->schema->embedding => new Vector($item->embedding->values, $item->embedding->dimensions()), $this->schema->embeddingProvider => $item->embedding->space->provider, $this->schema->embeddingModel => $item->embedding->space->model, $this->schema->embeddingDimensions => $item->embedding->space->dimensions, $this->schema->embeddingRevision => $item->embedding->space->revision, $this->schema->embeddingFingerprint => $item->embedding->space->fingerprint()];
        }
        $this->query->insertBatch($this->schema->table, $rows)->onConflict([$this->schema->tenantId, $this->schema->collection, $this->schema->chunkId, $this->schema->embeddingFingerprint])->doUpdate([$this->schema->content, $this->schema->metadata, $this->schema->status, $this->schema->embedding]);
        $this->query->execute();
    }

    /**
     * @param VectorSearchQuery $query
     * @return array|VectorSearchResult[]
     * @throws JsonException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\DatabaseException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
     */
    public function search(VectorSearchQuery $query): array
    {
        $space = $query->embedding->space;
        $fields = [$this->schema->chunkId, $this->schema->documentId, $this->schema->tenantId, $this->schema->collection, $this->schema->status, $this->schema->content, $this->schema->position, $this->schema->metadata];
        $metric = match ($query->policy->metric) {
            ContextVectorMetric::L2 => VectorMetric::L2,
            ContextVectorMetric::INNER_PRODUCT => VectorMetric::INNER_PRODUCT,
            ContextVectorMetric::COSINE => VectorMetric::COSINE,
            ContextVectorMetric::L1 => VectorMetric::L1,
        };
        $operation = $this->query->select($this->schema->table, $fields)->nearestNeighbors($this->schema->embedding, new Vector($query->embedding->values), $metric, 'distance', $space->dimensions)->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)->where($this->schema->status, SqlOperator::EQUALS, $query->status)->where($this->schema->embeddingProvider, SqlOperator::EQUALS, $space->provider)->where($this->schema->embeddingModel, SqlOperator::EQUALS, $space->model)->where($this->schema->embeddingDimensions, SqlOperator::EQUALS, $space->dimensions)->where($this->schema->embeddingRevision, SqlOperator::EQUALS, $space->revision)->where($this->schema->embeddingFingerprint, SqlOperator::EQUALS, $space->fingerprint());
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
            $found[] = new VectorSearchResult($chunk, $distance);
        }
        return $found;
    }

    /**
     * @param ChunkDeleteQuery $query
     * @return int
     * @throws \Omegaalfa\QueryBuilder\Exceptions\DatabaseException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
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
     * @throws \Omegaalfa\QueryBuilder\Exceptions\DatabaseException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
     */
    public function deleteDocument(DocumentDeleteQuery $query): int
    {
        $operation = $this->query->delete($this->schema->table)
            ->where($this->schema->tenantId, SqlOperator::EQUALS, $query->tenantId)
            ->where($this->schema->collection, SqlOperator::EQUALS, $query->collection)
            ->where($this->schema->documentId, SqlOperator::EQUALS, $query->documentId);
        if ($query->space !== null) {
            $this->applySpace($operation, $query->space);
        }

        return $operation->execute()->count;
    }

    /**
     * @param CollectionDeleteQuery $query
     * @return int
     * @throws \Omegaalfa\QueryBuilder\Exceptions\DatabaseException
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
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
     * @throws \Omegaalfa\QueryBuilder\Exceptions\QueryException
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
}
