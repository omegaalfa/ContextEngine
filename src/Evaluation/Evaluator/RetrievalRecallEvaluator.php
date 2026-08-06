<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Rag\RagExecution;

final readonly class RetrievalRecallEvaluator implements CaseEvaluator
{
    /** @return list<EvaluationScore> */
    public function evaluate(EvaluationCase $case, RagExecution $execution): array
    {
        $expected = $case->relevantChunkIds;
        $retrieved = $execution->diagnostics->retrieval->selectedChunkIds;

        if ($expected === [] && $case->relevantDocumentIds !== []) {
            $expected = $case->relevantDocumentIds;
            $retrieved = array_map(
                static fn ($source): string => $source->chunk->documentId,
                $execution->answer->sources,
            );
        }
        if ($expected === []) {
            return [];
        }

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
        $hitRate = $hits === [] ? 0.0 : 1.0;
        $reciprocalRank = $firstRank === null ? 0.0 : 1 / $firstRank;

        return [
            new EvaluationScore('recall', $recall, $recall >= 1.0),
            new EvaluationScore('precision', $precision, $hits !== []),
            new EvaluationScore('mrr', $reciprocalRank, $hits !== []),
            new EvaluationScore('hit_rate', $hitRate, $hits !== []),
        ];
    }
}
