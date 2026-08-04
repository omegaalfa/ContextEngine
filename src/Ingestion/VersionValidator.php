<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

class VersionValidator
{
    /**
     * @param list<DocumentVersion> $existingVersions
     * @return void
     */
    public function validate(DocumentVersion $version, array $existingVersions): void
    {
        if ($version->validFrom !== null && $version->validUntil !== null && $version->validFrom >= $version->validUntil) {
            throw new VersionValidationException($version->identity->documentId, [], 'Version validity window is invalid.');
        }

        if ($version->status === DocumentVersionStatus::DRAFT && $version->validFrom !== null) {
            throw new VersionValidationException($version->identity->documentId, [], 'Draft versions should not have a validity window.');
        }

        $detector = new VersionConflictDetector();
        $conflicts = $detector->detect([...$existingVersions, $version]);
        if ($conflicts !== []) {
            throw new VersionValidationException($version->identity->documentId, $conflicts, 'Version conflicts detected.');
        }
    }
}
