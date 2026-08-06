<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

final readonly class EvaluationReport
{
    /** @param list<EvaluationResult> $results */
    public function __construct(
        public string $datasetName,
        public array $results,
        public int $executedCases,
        public int $passedCases,
        public ?float $averageRecall,
        public ?float $averagePrecision,
        public ?float $meanReciprocalRank,
        public ?float $hitRate,
        public float $averageTimeMilliseconds,
        public float $totalTimeMilliseconds,
        public float $averageLatencyMilliseconds,
        public int $retrievedChunks,
        public int $selectedChunks,
    ) {}
}
