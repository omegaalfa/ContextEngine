<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Mede se a resposta trata diretamente dos termos centrais da pergunta.
 *
 * O gabarito factual não interfere nesta nota; assim, uma pergunta sobre
 * complexidade não exige detalhes adicionais que não foram solicitados.
 */
final readonly class AnswerRelevanceEvaluator implements AnswerEvaluator
{
    public function __construct(private float $passingScore = 0.8) {}

    /** Retorna null para casos negativos, avaliados pela política de ausência. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        if ($case->expectNoEvidence) {
            return null;
        }
        $question = $case->question instanceof \Omegaalfa\ContextEngine\Rag\Question ? $case->question->content : $case->question;
        $terms = \Omegaalfa\ContextEngine\Evaluation\Support\SignificantTerms::from($question);
        $answer = TextComparison::normalize($execution->answer->content);
        $matched = count(array_filter($terms, static fn (string $term): bool => str_contains($answer, $term)));
        $score = $terms === [] ? ($answer === '' ? 0.0 : 1.0) : $matched / count($terms);
        return new EvaluationScore('answer_relevance', $score, $score >= $this->passingScore);
    }
}
