<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\AnswerEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\AnswerRelevanceEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\CaseEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\CorrectnessEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\DeterministicTextualGroundednessEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\ExactMatchEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\ExpectedTermsEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\RetrievalRecallEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Metrics\GenerationMetrics;
use Omegaalfa\ContextEngine\Evaluation\Metrics\RetrievalMetrics;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Throwable;

final readonly class RagEvaluator
{
    /** @var list<CaseEvaluator|AnswerEvaluator> */
    private array $evaluators;
    private AnswerEvaluationPolicy $policy;

    /** @param list<CaseEvaluator|AnswerEvaluator>|null $evaluators */
    public function __construct(private ?string $tenantId = null, ?array $evaluators = null, ?AnswerEvaluationPolicy $policy = null)
    {
        if ($tenantId !== null && trim($tenantId) === '') {
            throw new InvalidArgumentException('Evaluation tenant id cannot be empty.');
        }
        $this->policy = $policy ?? new AnswerEvaluationPolicy();
        $this->evaluators = $evaluators ?? [
            new RetrievalRecallEvaluator(),
            new ExactMatchEvaluator(),
            new DeterministicTextualGroundednessEvaluator(passingScore: $this->policy->minimumGroundedness),
            new AnswerRelevanceEvaluator($this->policy->minimumAnswerRelevance),
            new CorrectnessEvaluator($this->policy->minimumCorrectness),
            new ExpectedTermsEvaluator(),
        ];
    }

    public function evaluate(RagPipeline $pipeline, EvaluationDataset $dataset): EvaluationReport
    {
        $started = hrtime(true);
        $results = [];
        foreach ($dataset as $case) {
            $results[] = $this->evaluateCase($pipeline, $case);
        }
        $total = self::elapsed($started);

        return new EvaluationReport(
            datasetName: $dataset->name,
            results: $results,
            executedCases: count($results),
            passedCases: count(array_filter($results, static fn (EvaluationResult $result): bool => $result->passed)),
            averageRecall: self::average($results, static fn (EvaluationResult $result): ?float => $result->retrieval->recall),
            averagePrecision: self::average($results, static fn (EvaluationResult $result): ?float => $result->retrieval->precision),
            meanReciprocalRank: self::average($results, static fn (EvaluationResult $result): ?float => $result->retrieval->reciprocalRank),
            hitRate: self::average($results, static fn (EvaluationResult $result): ?float => $result->retrieval->hitRate),
            averageTimeMilliseconds: $results === [] ? 0.0 : array_sum(array_column($results, 'durationMilliseconds')) / count($results),
            totalTimeMilliseconds: $total,
            averageLatencyMilliseconds: self::averageLatency($results),
            retrievedChunks: self::chunkCount($results, 'fusedChunkIds'),
            selectedChunks: self::chunkCount($results, 'selectedChunkIds'),
        );
    }

    private function evaluateCase(RagPipeline $pipeline, EvaluationCase $case): EvaluationResult
    {
        $started = hrtime(true);
        try {
            $question = $this->question($case);
            $execution = $pipeline->askWithDiagnostics($question);
            $scores = [];
            foreach ($this->evaluators as $evaluator) {
                $evaluated = $evaluator->evaluate($case, $execution);
                $evaluated = $evaluated instanceof \Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore ? [$evaluated] : ($evaluated ?? []);
                foreach ($evaluated as $score) {
                    $scores[$score->name] = $score;
                }
            }
            $applicable = array_values($scores);
            $passed = $applicable !== [] && $this->passes($scores);

            return new EvaluationResult(
                case: $case,
                passed: $passed,
                scores: $scores,
                retrieval: new RetrievalMetrics(
                    $scores['chunk_recall']->value ?? null,
                    $scores['chunk_precision']->value ?? null,
                    $scores['chunk_mrr']->value ?? null,
                    $scores['chunk_hit_rate']->value ?? null,
                ),
                generation: new GenerationMetrics(
                    $scores['normalized_exact_match']->value ?? null,
                    $scores['contains_expected_terms']->value ?? null,
                    $scores['strict_exact_match']->value ?? null,
                    $scores['normalized_exact_match']->value ?? null,
                ),
                durationMilliseconds: self::elapsed($started),
                execution: $execution,
                status: $applicable === []
                    ? EvaluationStatus::NOT_APPLICABLE
                    : ($passed ? EvaluationStatus::PASSED : EvaluationStatus::FAILED),
            );
        } catch (Throwable $exception) {
            return new EvaluationResult(
                case: $case,
                passed: false,
                scores: [],
                retrieval: new RetrievalMetrics(),
                generation: new GenerationMetrics(),
                durationMilliseconds: self::elapsed($started),
                error: $exception::class.': '.$exception->getMessage(),
                status: EvaluationStatus::ERROR,
            );
        }
    }

    /** @param array<string, \Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore> $scores */
    private function passes(array $scores): bool
    {
        foreach ($scores as $name => $score) {
            if (($name === 'no_evidence' || str_starts_with($name, 'chunk_') || str_starts_with($name, 'document_') || $name === 'evidence_recall')
                && !str_ends_with($name, '_hit_at_1') && !$score->passed) {
                return false;
            }
        }
        return $this->policy->passes($scores);
    }

    private function question(EvaluationCase $case): Question
    {
        if ($case->question instanceof Question) {
            return $case->question;
        }
        $tenantId = $case->tenantId ?? $this->tenantId;
        if ($tenantId === null) {
            throw new InvalidArgumentException('A tenant id is required for string evaluation questions.');
        }
        return new Question($case->question, $tenantId);
    }

    /** @param list<EvaluationResult> $results */
    private static function average(array $results, callable $value): ?float
    {
        $values = array_values(array_filter(array_map($value, $results), static fn (?float $item): bool => $item !== null));
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param list<EvaluationResult> $results */
    private static function averageLatency(array $results): float
    {
        $values = [];
        foreach ($results as $result) {
            if ($result->execution !== null) {
                $values[] = $result->execution->diagnostics->timingsMilliseconds['total'] ?? $result->durationMilliseconds;
            }
        }
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param list<EvaluationResult> $results */
    private static function chunkCount(array $results, string $property): int
    {
        $count = 0;
        foreach ($results as $result) {
            if ($result->execution !== null) {
                $count += count($result->execution->diagnostics->retrieval->{$property});
            }
        }
        return $count;
    }

    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
