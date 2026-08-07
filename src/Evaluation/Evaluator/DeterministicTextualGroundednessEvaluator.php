<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\GroundednessResult;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Evaluation\Support\SignificantTerms;
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
    public function __construct(private float $minimumCoverage = 0.6, private float $passingScore = 0.8) {}

    /** Retorna a nota e um GroundednessResult rastreável em EvaluationScore::details. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore
    {
        if ($case->expectNoEvidence || $execution->answer->sources === []) {
            return null;
        }
        $supported = [];
        $unsupported = [];
        foreach (self::claims($execution->answer->content) as $claim) {
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

    /** @return list<string> */
    private static function claims(string $answer): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/(?<=[.!?;])\s+|\R+|\s+e\s+(?=(?:foi|é|era|possui|tem|representa|criou|aceita)\b)/iu', trim($answer)) ?: [],
        ), static fn (string $claim): bool => $claim !== ''));
    }

    /** @return array{evidence:string,chunkId:string}|null */
    private function evidence(string $claim, RagExecution $execution): ?array
    {
        $claimNormalized = TextComparison::normalize($claim);
        $terms = SignificantTerms::from($claim);
        $protected = SignificantTerms::protected($claim);
        foreach ($execution->answer->sources as $source) {
            $content = TextComparison::normalize($source->chunk->content);
            if (self::negated($claimNormalized) !== self::negated($content)) {
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
                return ['evidence' => self::excerpt($source->chunk->content, $terms), 'chunkId' => $source->chunk->id];
            }
        }
        return null;
    }

    private static function negated(string $text): bool
    {
        return preg_match('/\b(?:não|nunca|jamais|sem)\b/u', $text) === 1;
    }

    /** @param list<string> $terms */
    private static function excerpt(string $content, array $terms): string
    {
        foreach (preg_split('/(?<=[.!?;])\s+|\R+/u', $content) ?: [] as $sentence) {
            $normalized = TextComparison::normalize($sentence);
            if (array_any($terms, static fn (string $term): bool => str_contains($normalized, $term))) {
                return trim($sentence);
            }
        }
        return mb_substr(trim($content), 0, 240);
    }
}
