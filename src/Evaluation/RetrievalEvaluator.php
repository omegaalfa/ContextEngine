<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\RetrievalOutcome;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Throwable;

final readonly class RetrievalEvaluator
{
    public function __construct(private ?string $tenantId = null)
    {
        if ($tenantId !== null && trim($tenantId) === '') {
            throw new InvalidArgumentException('Evaluation tenant id cannot be empty.');
        }
    }

    public function evaluate(Retriever $retriever, EvaluationDataset $dataset): RetrievalEvaluationReport
    {
        $started = hrtime(true);
        $results = [];
        foreach ($dataset as $case) {
            $results[] = $this->evaluateCase($retriever, $case);
        }
        return new RetrievalEvaluationReport($dataset->name, $results, self::elapsed($started));
    }

    private function evaluateCase(Retriever $retriever, EvaluationCase $case): RetrievalEvaluationResult
    {
        $started = hrtime(true);
        try {
            $outcome = $retriever->retrieveWithDiagnostics($this->question($case));
            $scores = $this->scores($case, $outcome);
            $status = $scores === []
                ? EvaluationStatus::NOT_APPLICABLE
                : (array_any($scores, static fn (EvaluationScore $score): bool => !$score->passed)
                    ? EvaluationStatus::FAILED
                    : EvaluationStatus::PASSED);
            return new RetrievalEvaluationResult($case, $status, $scores, self::elapsed($started), $outcome);
        } catch (Throwable $exception) {
            return new RetrievalEvaluationResult(
                $case,
                EvaluationStatus::ERROR,
                [],
                self::elapsed($started),
                error: $exception::class.': '.$exception->getMessage(),
            );
        }
    }

    /** @return array<string, EvaluationScore> */
    private function scores(EvaluationCase $case, RetrievalOutcome $outcome): array
    {
        if ($case->expectNoEvidence) {
            $passed = $outcome->results === [];
            return ['no_evidence' => new EvaluationScore('no_evidence', $passed ? 1.0 : 0.0, $passed)];
        }
        $scores = [];
        if ($case->hasChunkGroundTruth) {
            $scores += self::rankScores('chunk', $case->relevantChunkIds, array_map(static fn ($result): string => $result->chunk->id, $outcome->results));
        }
        if ($case->hasDocumentGroundTruth) {
            $scores += self::rankScores('document', $case->relevantDocumentIds, array_map(static fn ($result): string => $result->chunk->documentId, $outcome->results));
        }
        return $scores;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $retrieved
     * @return array<string, EvaluationScore>
     */
    private static function rankScores(string $prefix, array $expected, array $retrieved): array
    {
        $expected = array_values(array_unique($expected));
        $retrieved = array_values(array_unique($retrieved));
        $hits = array_values(array_intersect($retrieved, $expected));
        $firstRank = null;
        foreach ($retrieved as $offset => $id) {
            if (in_array($id, $expected, true)) {
                $firstRank = $offset + 1;
                break;
            }
        }
        $recall = count($hits) / count($expected);
        $precision = $retrieved === [] ? 0.0 : count($hits) / count($retrieved);
        $mrr = $firstRank === null ? 0.0 : 1 / $firstRank;
        $hitRate = $hits === [] ? 0.0 : 1.0;
        return [
            $prefix.'_recall' => new EvaluationScore($prefix.'_recall', $recall, $recall >= 1.0),
            $prefix.'_precision' => new EvaluationScore($prefix.'_precision', $precision, $hits !== []),
            $prefix.'_mrr' => new EvaluationScore($prefix.'_mrr', $mrr, $hits !== []),
            $prefix.'_hit_rate' => new EvaluationScore($prefix.'_hit_rate', $hitRate, $hits !== []),
        ];
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

    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
