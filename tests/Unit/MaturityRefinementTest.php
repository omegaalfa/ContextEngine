<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\AbstentionPolicy;
use Omegaalfa\ContextEngine\Evaluation\Support\EnglishTextAnalysisProfile;
use Omegaalfa\ContextEngine\Evaluation\Support\PortugueseTextAnalysisProfile;
use Omegaalfa\ContextEngine\HighLevel\RetrievalConfig;
use Omegaalfa\ContextEngine\Ingestion\Chunking\HeuristicTokenEstimator;
use Omegaalfa\ContextEngine\Retrieval\HybridEvidencePolicy;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use PHPUnit\Framework\TestCase;

final class MaturityRefinementTest extends TestCase
{
    public function testLexicalLanguageDefaultsToPortugueseAndAcceptsSafeConfigurations(): void
    {
        self::assertSame('portuguese', new LexicalSearchQuery('tenant', 'termos')->textSearchConfiguration);
        self::assertSame('english', new LexicalSearchQuery('tenant', 'terms', textSearchConfiguration: 'english')->textSearchConfiguration);
        self::assertSame('simple', new LexicalSearchQuery('tenant', 'ABC-123', textSearchConfiguration: 'simple')->textSearchConfiguration);
    }

    public function testLexicalLanguageRejectsSqlFragments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LexicalSearchQuery('tenant', 'terms', textSearchConfiguration: "english'); DROP TABLE chunks; --");
    }

    public function testCandidatePoolConfigurationPreservesLegacyNullDefaults(): void
    {
        $legacy = new RetrievalConfig(retrievalLimit: 5);
        self::assertNull($legacy->lexicalCandidateLimit);
        self::assertNull($legacy->rerankerCandidateLimit);

        $configured = new RetrievalConfig(retrievalLimit: 30, fusedLimit: 30, contextChunkLimit: 5, lexicalCandidateLimit: 30, rerankerCandidateLimit: 5);
        self::assertSame(30, $configured->lexicalCandidateLimit);
        self::assertSame(5, $configured->rerankerCandidateLimit);
    }

    public function testHybridEvidencePolicyImplementsAbstentionContractAndExplainsDecision(): void
    {
        $policy = new HybridEvidencePolicy();
        self::assertInstanceOf(AbstentionPolicy::class, $policy);
        $decision = $policy->evaluate('Como funciona o algoritmo Wesley?', [
            new VectorSearchResult(new Chunk('chunk', 'doc', 'tenant', 'Dijkstra encontra caminhos mínimos.', 0), 0.4),
        ]);
        self::assertTrue($decision->abstained());
        self::assertSame('isolated_vector_hit_without_named_term', $decision->reason);
        self::assertSame('Wesley', $decision->signals['namedTerms']);
    }

    public function testHybridEvidencePolicyExplainsEmptyCandidateAbstention(): void
    {
        $decision = new HybridEvidencePolicy()->evaluate('Pergunta', []);
        self::assertTrue($decision->abstained());
        self::assertSame('no_candidates', $decision->reason);
    }

    public function testPortugueseProfilePreservesCurrentNegationAndClaimSplitting(): void
    {
        $profile = new PortugueseTextAnalysisProfile();
        self::assertTrue($profile->isNegated('o algoritmo não aceita pesos'));
        self::assertTrue($profile->isNegated('nunca funciona sem dados'));
        self::assertCount(2, $profile->claims('Bellman-Ford tem complexidade O(VE) e foi criado por Wesley.'));
    }

    public function testEnglishProfileHandlesExplicitNegationForms(): void
    {
        $profile = new EnglishTextAnalysisProfile();
        foreach (['is not correct', 'does not have support', 'never converges', 'works without evidence'] as $text) {
            self::assertTrue($profile->isNegated($text), $text);
        }
        self::assertFalse($profile->isNegated('is correct and has support'));
        self::assertCount(2, $profile->claims('Bellman-Ford is O(VE) and was created by Wesley.'));
    }

    public function testHeuristicTokenFactorIsConfigurableAndFingerprintTracksIt(): void
    {
        $estimator = new HeuristicTokenEstimator(3);
        self::assertSame(2, $estimator->estimate('abcdef'));
        self::assertStringEndsWith(':3', $estimator->fingerprint());
    }
}
