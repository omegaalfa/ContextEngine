<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Contract\Reranker;
use Omegaalfa\ContextEngine\Evaluation\RetrievalEvaluator;
use Omegaalfa\ContextEngine\Provider\Cohere\CohereReranker;
use Omegaalfa\ContextEngine\Retrieval\DeterministicTextualReranker;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\HybridEvidencePolicy;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

const TENANT = 'evaluation-reranker-live';
const COLLECTION = 'evaluation-reranker-live';
const CANDIDATE_LIMIT = 20;
const FINAL_LIMIT = 5;

try {
    $golden = algorithms_golden(TENANT, COLLECTION);
    $context = ContextEngine::create()
        ->tenant(TENANT)
        ->collection(COLLECTION)
        ->ingestion(chunkSize: 700, chunkOverlap: 0)
        ->build();

    foreach ($golden['paths'] as $path) {
        $context->ingest(algorithms_loader($path, TENANT, COLLECTION));
    }
    if (!$context->store instanceof LexicalSearchStore) {
        throw new RuntimeException('The configured vector store does not support lexical search.');
    }

    $build = static fn (?Reranker $reranker): Retriever => new Retriever(
        embeddings: $context->embeddings,
        store: $context->store,
        policy: new RetrievalPolicy(limit: CANDIDATE_LIMIT, maximumDistance: null, metric: VectorMetric::COSINE),
        collection: COLLECTION,
        queryRewriter: new HeuristicQueryRewriter(3),
        neighborExpansion: new NeighborExpansion(),
        fusedLimit: CANDIDATE_LIMIT,
        contextChunkLimit: FINAL_LIMIT,
        maximumContextCharacters: 4_000,
        lexicalStore: $context->store,
        rankingWeights: ['vector' => 0.5, 'lexical' => 1.0],
        evidencePolicy: new HybridEvidencePolicy(),
        reranker: $reranker,
    );

    $baseline = new RetrievalEvaluator(TENANT)->evaluate($build(null), $golden['dataset']);
    $textual = new RetrievalEvaluator(TENANT)->evaluate($build(new DeterministicTextualReranker()), $golden['dataset']);
    $reports = ['Sem reranker' => $baseline, 'Textual' => $textual];
    $cohereApiKey = trim((string) getenv('COHERE_API_KEY'));
    if ($cohereApiKey !== '') {
        $cohereModel = trim((string) (getenv('COHERE_RERANK_MODEL') ?: 'rerank-v4.0-pro'));
        $reports['Cohere '.$cohereModel] = new RetrievalEvaluator(TENANT)->evaluate(
            $build(new CohereReranker($cohereApiKey, $cohereModel)),
            $golden['dataset'],
        );
    }

    echo PHP_EOL.str_repeat('=', 76).PHP_EOL;
    echo '  ContextEngine - Comparação live de reranker'.PHP_EOL;
    echo str_repeat('=', 76).PHP_EOL;
    echo 'Infraestrutura: embeddings configurados + PgVector + lexical PostgreSQL'.PHP_EOL;
    echo 'Candidatos após RRF: '.CANDIDATE_LIMIT.' | chunks finais: '.FINAL_LIMIT.PHP_EOL;
    echo 'Cenários: '.implode(' vs ', array_keys($reports)).PHP_EOL;
    if ($cohereApiKey === '') {
        echo 'Cohere: ignorado; defina COHERE_API_KEY para incluir o cross-encoder.'.PHP_EOL;
    }
    echo PHP_EOL.sprintf("%-28s %10s %10s %8s %8s %10s %10s %10s %11s %7s\n", 'Pipeline', 'Recall@5', 'Precision', 'MRR', 'Hit@1', 'DocRecall', 'Doc MRR', 'Doc Hit@1', 'Latência', 'Fallback');
    echo str_repeat('-', 130).PHP_EOL;
    foreach ($reports as $label => $report) {
        $fallbacks = array_sum(array_map(
            static fn ($result): int => $result->outcome?->diagnostics->rerankerFallbackCount ?? 0,
            $report->results,
        ));
        echo sprintf(
            "%-28s %10s %10s %8s %8s %10s %10s %10s %8.2f ms %7d\n",
            $label,
            number_format((float) $report->metric('chunk_recall'), 2),
            number_format((float) $report->metric('chunk_precision'), 2),
            number_format((float) $report->metric('chunk_mrr'), 2),
            number_format((float) $report->metric('chunk_hit_at_1'), 2),
            number_format((float) $report->metric('document_recall'), 2),
            number_format((float) $report->metric('document_mrr'), 2),
            number_format((float) $report->metric('document_hit_at_1'), 2),
            $report->averageLatencyMilliseconds(),
            $fallbacks,
        );
    }
    echo PHP_EOL.'Este benchmark não chama o modelo de linguagem.'.PHP_EOL;
    echo 'Execute novamente com: php examples/evaluation/compare-reranker-live.php'.PHP_EOL.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Live reranker comparison unavailable: '.$exception->getMessage().PHP_EOL);
    fwrite(STDERR, 'Configure embeddings and PostgreSQL/pgvector before running this example.'.PHP_EOL);
    exit(1);
}
