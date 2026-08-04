<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Omegaalfa\ContextEngine\Contract\BatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\TextSplitter;
use Omegaalfa\ContextEngine\Contract\VersionedVectorStore;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Ingestion\BatchEmbeddingResult;
use Omegaalfa\ContextEngine\Ingestion\BatchExecutionProgress;
use Omegaalfa\ContextEngine\Ingestion\DocumentVersion;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Ingestion\VersionValidationException;
use Omegaalfa\ContextEngine\Ingestion\VersionValidator;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Support\Batcher;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;
use PHPUnit\Framework\TestCase;

final class IngestionPipelineVersionValidationTest extends TestCase
{
    public function testValidatorRejectsConflictingValidityWindows(): void
    {
        $validator = new VersionValidator();
        $space = new EmbeddingSpace('provider', 'model', 3, 'a');
        $base = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $first = new \Omegaalfa\ContextEngine\Ingestion\DocumentVersion(
            new Document('doc', 'tenant', 'content', collection: 'docs'),
            $space,
            'splitter-a',
            validFrom: $base,
            validUntil: $base->modify('+2 months'),
        );
        $second = new \Omegaalfa\ContextEngine\Ingestion\DocumentVersion(
            new Document('doc', 'tenant', 'content-2', collection: 'docs'),
            $space,
            'splitter-a',
            validFrom: $base->modify('+1 month'),
            validUntil: $base->modify('+3 months'),
        );

        $this->expectException(VersionValidationException::class);
        $validator->validate($second, [$first]);
    }

    public function testPipelineUsesValidatorBeforeActivation(): void
    {
        $space = new EmbeddingSpace('provider', 'model', 3, 'a');
        $validator = new class () extends VersionValidator {
            public function validate(DocumentVersion $version, array $existingVersions): void
            {
                throw new VersionValidationException($version->identity->documentId, [], 'validator-hook');
            }
        };

        $pipeline = new IngestionPipeline(
            new class () implements TextSplitter {
                public function split(Document $document): array { return []; }
                public function fingerprint(): string { return 'splitter'; }
            },
            new class ($space) implements EmbeddingProvider {
                public function __construct(private EmbeddingSpace $space) {}
                public function space(): EmbeddingSpace { return $this->space; }
                public function embed(string $text, string $tenantId): Embedding { return new Embedding([1.0, 0.0, 0.0], $this->space); }
                public function embedBatch(EmbeddingBatchRequest $request): array { return []; }
            },
            new class () implements VersionedVectorStore, VectorStore {
                public function beginVersion(DocumentVersion $version): void {}
                public function stageBatch(DocumentVersion $version, array $chunks): void {}
                public function activateVersion(DocumentVersion $version): void {}
                public function failVersion(DocumentVersion $version): void {}
                public function storeBatch(array $chunks): void {}
                public function search(VectorSearchQuery $query): array { return []; }
                public function deleteChunk(ChunkDeleteQuery $query): int { return 0; }
                public function deleteDocument(DocumentDeleteQuery $query): int { return 0; }
                public function clearCollection(CollectionDeleteQuery $query): int { return 0; }
            },
            new class () implements BatchEmbeddingExecutor {
                public function execute(iterable $batches, EmbeddingProvider $provider): iterable {
                    return [];
                }
            },
            1,
            new Batcher(),
            $validator,
        );

        $loader = new class () implements DocumentLoader {
            public function load(): iterable { yield new Document('doc', 'tenant', 'content', collection: 'docs'); }
        };

        $this->expectException(\Omegaalfa\ContextEngine\Exception\IngestionException::class);
        $pipeline->ingest($loader);
    }
}
