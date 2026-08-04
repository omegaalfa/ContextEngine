<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

final readonly class DocumentVersion
{
    public string $id;
    public DocumentVersionIdentity $identity;

    public function __construct(
        public Document $document,
        public EmbeddingSpace $space,
        public string $chunkingFingerprint,
        public DocumentVersionStatus $status = DocumentVersionStatus::ACTIVE,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
        public int $revision = 1,
        public ?string $supersedesVersionId = null,
    ) {
        if (trim($chunkingFingerprint) === '') {
            throw new InvalidArgumentException('Chunking fingerprint cannot be empty.');
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('Revision must be greater than zero.');
        }
        if ($validFrom !== null && $validUntil !== null && $validFrom >= $validUntil) {
            throw new InvalidArgumentException('validFrom must be earlier than validUntil.');
        }
        if ($supersedesVersionId !== null && trim($supersedesVersionId) === '') {
            throw new InvalidArgumentException('Supersedes version id cannot be empty when provided.');
        }

        $metadata = $document->metadata;
        ksort($metadata);
        $this->id = hash('sha256', implode("\0", [
            $document->tenantId,
            $document->collection,
            $document->id,
            $document->status,
            $document->content,
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $space->fingerprint(),
            $chunkingFingerprint,
            $status->value,
            (string) $revision,
            $validFrom?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') ?? '',
            $validUntil?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') ?? '',
            $supersedesVersionId ?? '',
        ]));
        $this->identity = new DocumentVersionIdentity(
            $document->id,
            $this->id,
            $revision,
        );
    }

    public function isValidAt(DateTimeImmutable $instant): bool
    {
        if ($this->validFrom !== null && $instant < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $instant >= $this->validUntil) {
            return false;
        }

        return true;
    }
}
