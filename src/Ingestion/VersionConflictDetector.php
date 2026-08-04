<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

final class VersionConflictDetector
{
    /**
     * @param list<DocumentVersion> $versions
     * @return list<VersionConflict>
     */
    public function detect(array $versions): array
    {
        $conflicts = [];
        $seen = [];

        foreach ($versions as $index => $version) {
            /** @phpstan-ignore instanceof.alwaysTrue */
            if (!$version instanceof DocumentVersion) {
                continue;
            }
            $key = $version->identity->documentId . ':' . $version->identity->versionId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            foreach (array_slice($versions, $index + 1) as $candidate) {
                /** @phpstan-ignore instanceof.alwaysTrue */
                if (!$candidate instanceof DocumentVersion) {
                    continue;
                }
                if ($candidate->identity->documentId !== $version->identity->documentId) {
                    continue;
                }

                if ($candidate->validFrom !== null && $version->validUntil !== null && $candidate->validFrom < $version->validUntil && $version->validFrom !== null && $candidate->validFrom >= $version->validFrom) {
                    $conflicts[] = new VersionConflict(
                        $version->identity->documentId,
                        $version->identity->versionId,
                        $candidate->identity->versionId,
                        'overlapping-validity-window',
                    );
                    break;
                }
            }
        }

        return $conflicts;
    }
}
