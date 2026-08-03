<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Retrieval\AdaptiveContextSelector;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\ContextSelectionDiagnostic;
use Omegaalfa\ContextEngine\Retrieval\ContextSelectionReason;
use Omegaalfa\ContextEngine\Retrieval\ContextSelector;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use PHPUnit\Framework\TestCase;

final class AdaptiveContextSelectorTest extends TestCase
{
    public function testFirstSourceSufficientDiscardsAdditionalNoise(): void
    {
        $outcome = $this->select(
            'Explique Fibonacci recursivo em PHP',
            [
                $this->makeResult('primary', 'doc-a', 'Fibonacci recursivo implementado em PHP', .10),
                $this->makeResult('noise', 'doc-b', 'Estrutura heap em Python', .12),
            ],
        );

        self::assertSame(['primary'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::DUPLICATE_EVIDENCE, $this->decision($outcome, 'noise')->reason);
    }

    public function testIncompletePrimaryKeepsSameDocumentSupport(): void
    {
        $outcome = $this->select(
            'Explique Fibonacci recursivo em PHP',
            [
                $this->makeResult('primary', 'doc-a', 'Exemplo escrito em PHP', .10),
                $this->makeResult('support', 'doc-a', 'A fun��o Fibonacci utiliza recurs�o', .14),
            ],
        );

        self::assertSame(['primary', 'support'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::SAME_DOCUMENT_SUPPORT, $this->decision($outcome, 'support')->reason);
    }

    public function testComplementaryAndDistantCoverageIsRetained(): void
    {
        $outcome = $this->select(
            'Compare Dijkstra e Bellman-Ford',
            [
                $this->makeResult('dijkstra', 'doc-a', 'Dijkstra encontra caminhos m�nimos', .10),
                $this->makeResult('bellman', 'doc-b', 'Bellman-Ford aceita pesos negativos', .40),
                $this->makeResult('heap', 'doc-c', 'Heap bin�rio', .41),
            ],
        );

        self::assertSame(['dijkstra', 'bellman'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::ADDITIONAL_COVERAGE, $this->decision($outcome, 'bellman')->reason);
        self::assertSame(ContextSelectionReason::DISTANCE_GAP, $this->decision($outcome, 'heap')->reason);
    }

    public function testSameDocumentIsPreferredBeforeEquivalentExternalCandidate(): void
    {
        $outcome = $this->select(
            'Explique PHP e Fibonacci',
            [
                $this->makeResult('primary', 'doc-a', 'C�digo PHP', .10),
                $this->makeResult('external', 'doc-b', 'Fibonacci', .11),
                $this->makeResult('same', 'doc-a', 'Fibonacci', .15),
            ],
        );

        self::assertSame(['primary', 'same'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::SAME_DOCUMENT_SUPPORT, $this->decision($outcome, 'same')->reason);
        self::assertSame(ContextSelectionReason::DUPLICATE_EVIDENCE, $this->decision($outcome, 'external')->reason);
    }

    public function testNecessaryNeighborIsPreserved(): void
    {
        $outcome = $this->select(
            'Explique assinatura e retorno',
            [
                $this->makeResult('primary', 'doc-a', 'Assinatura da fun��o', .10),
                $this->makeResult('neighbor', 'doc-a', 'O retorno cont�m a �rvore', .10, true),
            ],
        );

        self::assertSame(['primary', 'neighbor'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::NEIGHBOR_CONTEXT, $this->decision($outcome, 'neighbor')->reason);
    }

    public function testDuplicateEvidenceIsRemoved(): void
    {
        $outcome = $this->select(
            'Explique Fibonacci PHP',
            [
                $this->makeResult('primary', 'doc-a', 'Fibonacci PHP recursivo exemplo completo', .10),
                $this->makeResult('copy', 'doc-b', 'Fibonacci PHP recursivo exemplo completo', .11),
            ],
        );

        self::assertSame(['primary'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::DUPLICATE_EVIDENCE, $this->decision($outcome, 'copy')->reason);
    }

    public function testMinimumAndMaximumSourcesAreRespected(): void
    {
        $selector = new AdaptiveContextSelector(new ContextRelevancePolicy(
            maximumDistanceGap: .08,
            minimumSources: 2,
            maximumSources: 2,
        ));
        $outcome = $selector->select('Explique PHP', [
            $this->makeResult('primary', 'doc-a', 'PHP completo', .10),
            $this->makeResult('minimum', 'doc-b', 'Exemplo auxiliar', .11),
            $this->makeResult('limited', 'doc-c', 'PHP configura��o', .12),
        ]);

        self::assertSame(['primary', 'minimum'], $this->ids($outcome['selected']));
        self::assertSame(ContextSelectionReason::SOURCE_LIMIT, $this->decision($outcome, 'limited')->reason);
    }

    public function testSelectionIsDeterministic(): void
    {
        $candidates = [
            $this->makeResult('a', 'doc-a', 'PHP', .10),
            $this->makeResult('b', 'doc-b', 'Fibonacci', .12),
            $this->makeResult('c', 'doc-c', 'Ru�do', .40),
        ];
        $selector = new AdaptiveContextSelector(new ContextRelevancePolicy());

        self::assertEquals(
            $selector->select('PHP Fibonacci', $candidates),
            $selector->select('PHP Fibonacci', $candidates),
        );
    }

    public function testExistingBudgetStillAppliesAndReportsItsReason(): void
    {
        $adaptive = $this->select('PHP Fibonacci', [
            $this->makeResult('primary', 'doc-a', 'PHP', .10),
            $this->makeResult('support', 'doc-b', 'Fibonacci completo', .11),
        ]);
        $budget = new ContextSelector(chunkLimit: 5, maximumCharacters: 4)
            ->select($adaptive['selected']);

        self::assertSame(['primary'], $this->ids($budget['selected']));
        self::assertSame(ContextSelectionReason::CONTEXT_BUDGET, $budget['discardReasons']['support']);
    }

    public function testDisabledCompatibilityUsesTheExistingSequentialSelector(): void
    {
        $candidates = [
            $this->makeResult('a', 'doc-a', 'PHP completo', .10),
            $this->makeResult('b', 'doc-b', 'Ru�do distante', .90),
        ];

        self::assertSame($candidates, new ContextSelector(5)->select($candidates)['selected']);
    }

    public function testPolicyRejectsInvalidLimitsAndGap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContextRelevancePolicy(maximumDistanceGap: -0.1, minimumSources: 2, maximumSources: 1);
    }

    /**
     * @param list<VectorSearchResult> $candidates
     * @return array{selected: list<VectorSearchResult>, decisions: list<ContextSelectionDiagnostic>}
     */
    private function select(string $question, array $candidates): array
    {
        return new AdaptiveContextSelector(new ContextRelevancePolicy())->select($question, $candidates);
    }

    private function makeResult(
        string $id,
        string $document,
        string $content,
        float $distance,
        bool $neighbor = false,
    ): VectorSearchResult {
        return new VectorSearchResult(
            new Chunk($id, $document, 'tenant', $content, 0),
            $distance,
            'version',
            $neighbor,
        );
    }

    /** @param list<VectorSearchResult> $results @return list<string> */
    private function ids(array $results): array
    {
        return array_map(static fn (VectorSearchResult $result): string => $result->chunk->id, $results);
    }

    /**
     * @param array{selected: list<VectorSearchResult>, decisions: list<ContextSelectionDiagnostic>} $outcome
     */
    private function decision(array $outcome, string $chunkId): ContextSelectionDiagnostic
    {
        foreach ($outcome['decisions'] as $decision) {
            if ($decision->chunkId === $chunkId) {
                return $decision;
            }
        }
        self::fail('Missing decision for chunk ' . $chunkId);
    }
}
