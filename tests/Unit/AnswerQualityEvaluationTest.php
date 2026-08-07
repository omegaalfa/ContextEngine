<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\AnswerRelevanceEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\CorrectnessEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\DeterministicGroundednessEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Evaluator\DeterministicTextualGroundednessEvaluator;
use Omegaalfa\ContextEngine\Evaluation\ExpectedClaim;
use Omegaalfa\ContextEngine\Evaluation\GroundednessResult;
use Omegaalfa\ContextEngine\Rag\Answer;
use Omegaalfa\ContextEngine\Rag\RagDiagnostics;
use Omegaalfa\ContextEngine\Rag\RagExecution;
use Omegaalfa\ContextEngine\Retrieval\RetrievalDiagnostics;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use PHPUnit\Framework\TestCase;

final class AnswerQualityEvaluationTest extends TestCase
{
    public function testGroundednessTracesSupportedAndUnsupportedClaims(): void
    {
        $execution = $this->execution('Bellman-Ford possui complexidade O(VE). Wesley criou o algoritmo.', 'O algoritmo Bellman-Ford possui complexidade O(VE) e aceita pesos negativos.');
        $score = new DeterministicGroundednessEvaluator()->evaluate(new EvaluationCase('case', 'Qual a complexidade do Bellman-Ford?', expectedTerms: ['Bellman-Ford']), $execution);

        self::assertNotNull($score);
        self::assertSame(0.5, $score->value);
        self::assertInstanceOf(GroundednessResult::class, $score->details);
        self::assertCount(1, $score->details->supportedClaims);
        self::assertSame(['Wesley criou o algoritmo.'], $score->details->unsupportedClaims);
    }

    public function testGroundednessRejectsInsertedNegation(): void
    {
        $score = $this->groundedness('A complexidade do Bellman-Ford não é O(VE).', 'A complexidade do Bellman-Ford é O(VE).');

        self::assertSame(0.0, $score->value);
    }

    public function testGroundednessRejectsSwappedIdentifier(): void
    {
        $score = $this->groundedness('e[i][j] representa a soma das probabilidades.', 'w[i][j] representa a soma das probabilidades.');

        self::assertSame(0.0, $score->value);
    }

    public function testGroundednessRejectsChangedComplexity(): void
    {
        $score = $this->groundedness('Bellman-Ford possui complexidade O(V²).', 'Bellman-Ford possui complexidade O(VE).');

        self::assertSame(0.0, $score->value);
    }

    public function testGroundednessSeparatesSupportedAndInventedConjunctiveClaims(): void
    {
        $score = $this->groundedness(
            'Bellman-Ford tem complexidade O(VE) e foi criado por Wesley em 1999.',
            'Bellman-Ford tem complexidade O(VE).',
        );

        self::assertSame(0.5, $score->value);
        self::assertInstanceOf(GroundednessResult::class, $score->details);
        self::assertCount(1, $score->details->supportedClaims);
        self::assertCount(1, $score->details->unsupportedClaims);
    }

    public function testAnswerRelevanceDoesNotRequireUnaskedNegativeWeights(): void
    {
        $case = new EvaluationCase('bellman', 'Qual é a complexidade do Bellman-Ford?', expectedTerms: ['pesos negativos']);
        $score = new AnswerRelevanceEvaluator()->evaluate($case, $this->execution('O Bellman-Ford possui complexidade O(VE).', 'Bellman-Ford possui complexidade O(VE).'));

        self::assertNotNull($score);
        self::assertSame(1.0, $score->value);
    }

    public function testCorrectnessUsesStructuredClaimAlternatives(): void
    {
        $case = new EvaluationCase('correctness', 'Qual é a complexidade?', expectedClaims: [new ExpectedClaim('complexity', ['O(VE)', 'O(V * E)', 'O(|V||E|)'])]);
        $score = new CorrectnessEvaluator()->evaluate($case, $this->execution('A complexidade é O(V * E).', 'Complexidade O(VE).'));

        self::assertNotNull($score);
        self::assertSame(1.0, $score->value);
        self::assertSame(['matchedClaims' => ['complexity']], $score->details);
    }

    public function testCorrectnessIsNotApplicableWithoutGroundTruth(): void
    {
        $case = new EvaluationCase('relevance-only', 'O que é AVL?', expectedTerms: ['AVL']);

        self::assertNull(new CorrectnessEvaluator()->evaluate($case, $this->execution('AVL é uma árvore balanceada.', 'AVL é balanceada.')));
    }

    private function execution(string $answer, string $context): RagExecution
    {
        $source = new VectorSearchResult(new Chunk('chunk', 'document', 'tenant', $context, 0), 0.1);
        $retrieval = new RetrievalDiagnostics('question', ['question'], ['question' => 1], ['question' => []], null, 0, ['chunk'], [], ['chunk'], [], mb_strlen($context), []);
        return new RagExecution(new Answer($answer, [$source]), new RagDiagnostics($retrieval, 0, true, [], []));
    }

    private function groundedness(string $answer, string $context): \Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore
    {
        $case = new EvaluationCase('groundedness', 'Pergunta', expectedTerms: ['diagnóstico']);
        return new DeterministicTextualGroundednessEvaluator()->evaluate($case, $this->execution($answer, $context))
            ?? self::fail('Groundedness should be applicable.');
    }
}
