<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class EvaluationCase
{
    /** @var list<string> */
    public array $relevantChunkIds;
    /** @var list<string> */
    public array $relevantDocumentIds;
    /** @var list<list<string>> */
    public array $expectedTermGroups;
    /** @var list<RelevantEvidence> */
    public array $relevantEvidence;
    /** @var list<ExpectedClaim> */
    public array $expectedClaims;
    public bool $hasChunkGroundTruth;
    public bool $hasDocumentGroundTruth;

    /**
     * @param list<string>|null $relevantChunkIds
     * @param list<string>|null $relevantDocumentIds
     * @param list<string> $expectedTerms
     * @param list<list<string>> $expectedTermGroups
     * @param list<RelevantEvidence> $relevantEvidence
     * @param list<ExpectedClaim> $expectedClaims
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $id,
        public string|Question $question,
        public ?string $expectedAnswer = null,
        ?array $relevantChunkIds = null,
        ?array $relevantDocumentIds = null,
        public array $metadata = [],
        public array $expectedTerms = [],
        public ?string $tenantId = null,
        array $expectedTermGroups = [],
        public bool $expectNoEvidence = false,
        array $relevantEvidence = [],
        array $expectedClaims = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Evaluation case id cannot be empty.');
        }
        if (is_string($question) && trim($question) === '') {
            throw new InvalidArgumentException('Evaluation question cannot be empty.');
        }
        if ($expectedAnswer !== null && trim($expectedAnswer) === '') {
            throw new InvalidArgumentException('Expected answer cannot be empty when provided.');
        }
        if ($relevantChunkIds === [] || $relevantDocumentIds === []) {
            throw new InvalidArgumentException('Configured retrieval ground truth cannot be an empty list. Use null when not applicable.');
        }
        $this->relevantChunkIds = $relevantChunkIds ?? [];
        $this->relevantDocumentIds = $relevantDocumentIds ?? [];
        $this->hasChunkGroundTruth = $relevantChunkIds !== null;
        $this->hasDocumentGroundTruth = $relevantDocumentIds !== null;
        foreach ([$this->relevantChunkIds, $this->relevantDocumentIds, $expectedTerms] as $values) {
            if (array_any($values, static fn (string $value): bool => trim($value) === '')) {
                throw new InvalidArgumentException('Evaluation identifiers and expected terms cannot be empty.');
            }
        }
        foreach ($expectedTermGroups as $group) {
            if ($group === [] || array_any($group, static fn (string $term): bool => trim($term) === '')) {
                throw new InvalidArgumentException('Expected term groups must contain non-empty string alternatives.');
            }
        }
        $this->expectedTermGroups = $expectedTermGroups;
        $this->relevantEvidence = $relevantEvidence;
        $this->expectedClaims = $expectedClaims;
        if ($expectNoEvidence && ($this->hasChunkGroundTruth || $this->hasDocumentGroundTruth || $expectedAnswer !== null || $expectedTerms !== [] || $expectedTermGroups !== [] || $relevantEvidence !== [] || $expectedClaims !== [])) {
            throw new InvalidArgumentException('Negative evaluation cases cannot define positive expectations.');
        }
    }
}
