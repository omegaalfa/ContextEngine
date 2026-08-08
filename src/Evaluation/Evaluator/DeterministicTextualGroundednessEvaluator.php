<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\GroundednessResult;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\PortugueseTextAnalysisProfile;
use Omegaalfa\ContextEngine\Evaluation\Support\SignificantTerms;
use Omegaalfa\ContextEngine\Evaluation\Support\TextAnalysisProfile;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Mede se as afirmações da resposta possuem apoio textual aproximado nas fontes.
 *
 * É offline e reproduzível. Detecta termos, identificadores, fórmulas, números e
 * negação simples, mas não garante equivalência lógica ou verdade universal.
 */
final readonly class DeterministicTextualGroundednessEvaluator implements AnswerEvaluator
{
    private TextAnalysisProfile $profile;

    public function __construct(
        private float $minimumCoverage = 0.6,
        private float $passingScore = 0.8,
        ?TextAnalysisProfile $profile = null,
    ) {
        $this->profile = $profile ?? new PortugueseTextAnalysisProfile();
    }

    /** Retorna a nota e um GroundednessResult rastreável em EvaluationScore::details. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        if ($case->expectNoEvidence || $execution->answer->sources === []) {
            return null;
        }
        $supported = [];
        $unsupported = [];
        foreach ($this->profile->claims($execution->answer->content) as $claim) {
            $evidence = $this->evidence($claim, $execution);
            if ($evidence === null) {
                $unsupported[] = $claim;
            } else {
                $supported[] = ['claim' => $claim, ...$evidence];
            }
        }
        $total = count($supported) + count($unsupported);
        $score = $total === 0 ? 0.0 : count($supported) / $total;
        return new EvaluationScore(
            'groundedness',
            $score,
            $score >= $this->passingScore,
            new GroundednessResult($score, $supported, $unsupported),
        );
    }

    /** @return array{evidence:string,chunkId:string}|null */
    private function evidence(string $claim, RagExecution $execution): ?array
    {
        $claimNormalized = TextComparison::normalize($claim);
        $terms = SignificantTerms::from($claim);
        $protected = SignificantTerms::protected($claim);
        foreach ($execution->answer->sources as $source) {
            $content = TextComparison::normalize($source->chunk->content);
            if ($this->profile->isNegated($claimNormalized) !== $this->profile->isNegated($content)) {
                continue;
            }
            if (str_contains($content, $claimNormalized)) {
                return ['evidence' => $claim, 'chunkId' => $source->chunk->id];
            }
            if ($protected !== [] && !array_all($protected, static fn (string $term): bool => str_contains($content, $term))) {
                continue;
            }
            $matched = count(array_filter($terms, static fn (string $term): bool => str_contains($content, $term)));
            if ($terms !== [] && $matched / count($terms) >= $this->minimumCoverage) {
                return ['evidence' => $this->excerpt($source->chunk->content, $terms), 'chunkId' => $source->chunk->id];
            }
        }
        return null;
    }

    /** @param list<string> $terms */
    private function excerpt(string $content, array $terms): string
    {
        foreach ($this->profile->sentences($content) as $sentence) {
            $normalized = TextComparison::normalize($sentence);
            if (array_any($terms, static fn (string $term): bool => str_contains($normalized, $term))) {
                return trim($sentence);
            }
        }
        return mb_substr(trim($content), 0, 240);
    }
}
