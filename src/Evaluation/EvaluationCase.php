<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class EvaluationCase
{
    /**
     * @param list<string> $relevantChunkIds
     * @param list<string> $relevantDocumentIds
     * @param list<string> $expectedTerms
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $id,
        public string|Question $question,
        public ?string $expectedAnswer = null,
        public array $relevantChunkIds = [],
        public array $relevantDocumentIds = [],
        public array $metadata = [],
        public array $expectedTerms = [],
        public ?string $tenantId = null,
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
        foreach ([$relevantChunkIds, $relevantDocumentIds, $expectedTerms] as $values) {
            if (array_any($values, static fn (string $value): bool => trim($value) === '')) {
                throw new InvalidArgumentException('Evaluation identifiers and expected terms cannot be empty.');
            }
        }
    }
}
