<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use JsonException;
use RuntimeException;

final class EvaluationDatasetLoader
{
    /** @throws JsonException */
    public function fromJson(string $json, string $name = 'Dataset'): EvaluationDataset
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Evaluation JSON must contain a list of cases.');
        }

        $cases = [];
        foreach ($rows as $offset => $row) {
            if (!is_array($row)) {
                throw new RuntimeException("Evaluation case at offset {$offset} must be an object.");
            }
            $cases[] = new EvaluationCase(
                id: self::string($row, 'id', $offset),
                question: self::string($row, 'question', $offset),
                expectedAnswer: self::optionalString($row, 'expectedAnswer', $offset),
                relevantChunkIds: self::optionalStringList($row, 'relevantChunkIds', $offset),
                relevantDocumentIds: self::optionalStringList($row, 'relevantDocumentIds', $offset),
                metadata: self::metadata($row, $offset),
                expectedTerms: self::stringList($row, 'expectedTerms', $offset),
                tenantId: self::optionalString($row, 'tenantId', $offset),
                expectedTermGroups: self::stringGroups($row, 'expectedTermGroups', $offset),
                expectNoEvidence: self::boolean($row, 'expectNoEvidence', $offset),
                expectedClaims: self::expectedClaims($row, $offset),
            );
        }

        return new EvaluationDataset($cases, $name);
    }

    /** @throws JsonException */
    public function fromFile(string $path, ?string $name = null): EvaluationDataset
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read evaluation dataset: {$path}");
        }

        return $this->fromJson($json, $name ?? pathinfo($path, PATHINFO_FILENAME));
    }

    /** @param array<mixed> $row */
    private static function string(array $row, string $key, int $offset): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new RuntimeException("Evaluation case at offset {$offset} requires string field '{$key}'.");
        }
        return $row[$key];
    }

    /** @param array<mixed> $row */
    private static function optionalString(array $row, string $key, int $offset): ?string
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }
        if (!is_string($row[$key])) {
            throw new RuntimeException("Evaluation field '{$key}' at offset {$offset} must be a string.");
        }
        return $row[$key];
    }

    /**
     * @param array<mixed> $row
     * @return list<string>
     */
    private static function stringList(array $row, string $key, int $offset): array
    {
        $value = $row[$key] ?? [];
        if (!is_array($value) || !array_is_list($value) || array_any($value, static fn ($item): bool => !is_string($item))) {
            throw new RuntimeException("Evaluation field '{$key}' at offset {$offset} must be a string list.");
        }
        return $value;
    }

    /**
     * @param array<mixed> $row
     * @return list<string>|null
     */
    private static function optionalStringList(array $row, string $key, int $offset): ?array
    {
        return array_key_exists($key, $row) ? self::stringList($row, $key, $offset) : null;
    }

    /**
     * @param array<mixed> $row
     * @return list<list<string>>
     */
    private static function stringGroups(array $row, string $key, int $offset): array
    {
        $groups = $row[$key] ?? [];
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new RuntimeException("Evaluation field '{$key}' at offset {$offset} must be a list of string lists.");
        }
        $result = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !array_is_list($group) || $group === [] || array_any($group, static fn ($term): bool => !is_string($term))) {
                throw new RuntimeException("Evaluation field '{$key}' at offset {$offset} contains an invalid group.");
            }
            $result[] = $group;
        }
        return $result;
    }

    /** @param array<mixed> $row */
    private static function boolean(array $row, string $key, int $offset): bool
    {
        $value = $row[$key] ?? false;
        if (!is_bool($value)) {
            throw new RuntimeException("Evaluation field '{$key}' at offset {$offset} must be boolean.");
        }
        return $value;
    }

    /**
     * @param array<mixed> $row
     * @return list<ExpectedClaim>
     */
    private static function expectedClaims(array $row, int $offset): array
    {
        $claims = $row['expectedClaims'] ?? [];
        if (!is_array($claims) || !array_is_list($claims)) {
            throw new RuntimeException("Evaluation field 'expectedClaims' at offset {$offset} must be a list.");
        }
        $result = [];
        foreach ($claims as $claim) {
            if (!is_array($claim) || !isset($claim['id'], $claim['alternatives']) || !is_string($claim['id']) || !is_array($claim['alternatives']) || !array_is_list($claim['alternatives']) || $claim['alternatives'] === [] || array_any($claim['alternatives'], static fn ($value): bool => !is_string($value))) {
                throw new RuntimeException("Evaluation field 'expectedClaims' at offset {$offset} contains an invalid claim.");
            }
            /** @var list<string> $alternatives */
            $alternatives = $claim['alternatives'];
            $result[] = new ExpectedClaim($claim['id'], $alternatives);
        }
        return $result;
    }

    /**
     * @param array<mixed> $row
     * @return array<string, scalar|null>
     */
    private static function metadata(array $row, int $offset): array
    {
        $metadata = $row['metadata'] ?? [];
        if (!is_array($metadata) || $metadata !== [] && array_is_list($metadata)
            || array_any($metadata, static fn ($value): bool => !is_scalar($value) && $value !== null)) {
            throw new RuntimeException("Evaluation metadata at offset {$offset} must contain only scalar values.");
        }
        $result = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_scalar($value) && $value !== null) {
                throw new RuntimeException("Evaluation metadata at offset {$offset} is invalid.");
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
