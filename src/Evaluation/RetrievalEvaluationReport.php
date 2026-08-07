<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

final readonly class RetrievalEvaluationReport
{
    /** @param list<RetrievalEvaluationResult> $results */
    public function __construct(
        public string $datasetName,
        public array $results,
        public float $totalTimeMilliseconds,
    ) {}

    public function metric(string $name): ?float
    {
        $values = [];
        foreach ($this->results as $result) {
            if (isset($result->scores[$name])) {
                $values[] = $result->scores[$name]->value;
            }
        }
        return $values === [] ? null : array_sum($values) / count($values);
    }

    public function denominator(string $name): int
    {
        return count(array_filter($this->results, static fn (RetrievalEvaluationResult $result): bool => isset($result->scores[$name])));
    }

    public function count(EvaluationStatus $status): int
    {
        return count(array_filter($this->results, static fn (RetrievalEvaluationResult $result): bool => $result->status === $status));
    }

    public function positiveCases(): int
    {
        return count(array_filter($this->results, static fn (RetrievalEvaluationResult $result): bool => !$result->case->expectNoEvidence));
    }

    public function negativeCases(): int
    {
        return count($this->results) - $this->positiveCases();
    }

    public function averageLatencyMilliseconds(): float
    {
        return $this->results === [] ? 0.0 : array_sum(array_column($this->results, 'durationMilliseconds')) / count($this->results);
    }
}
