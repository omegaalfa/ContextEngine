<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Evaluation\RetrievalEvaluator;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\HybridEvidencePolicy;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

const TENANT = 'evaluation-candidate-pool-live';
const COLLECTION = 'evaluation-candidate-pool-live';
const POOLS = [5, 10, 20, 30, 50];

try {
    $golden = algorithms_golden(TENANT, COLLECTION);
    $context = ContextEngine::create()->tenant(TENANT)->collection(COLLECTION)
        ->ingestion(chunkSize: 700, chunkOverlap: 0)->build();
    foreach ($golden['paths'] as $path) {
        $context->ingest(algorithms_loader($path, TENANT, COLLECTION));
    }
    if (!$context->store instanceof LexicalSearchStore) {
        throw new RuntimeException('O vector store configurado não oferece busca lexical.');
    }

    echo PHP_EOL.str_repeat('=', 116).PHP_EOL;
    echo '  ContextEngine - Candidate Pool Live'.PHP_EOL;
    echo str_repeat('=', 116).PHP_EOL;
    echo "Cada linha usa o mesmo limite para vetor, lexical e fused; contexto final = 20.\n\n";
    echo sprintf("%6s %10s %11s %11s %8s %8s %10s %9s %10s %12s\n", 'Pool', 'Recall@5', 'Recall@10', 'Recall@20', 'MRR', 'Hit@1', 'DocRecall', 'Doc MRR', 'Doc Hit@1', 'Latência');
    echo str_repeat('-', 116).PHP_EOL;

    foreach (POOLS as $pool) {
        $reports = [];
        foreach ([5, 10, 20] as $depth) {
            $retriever = new Retriever(
                embeddings: $context->embeddings,
                store: $context->store,
                policy: new RetrievalPolicy($pool, VectorMetric::COSINE, null),
                collection: COLLECTION,
                queryRewriter: new HeuristicQueryRewriter(3),
                neighborExpansion: new NeighborExpansion(),
                fusedLimit: $pool,
                contextChunkLimit: min($depth, $pool),
                lexicalStore: $context->store,
                rankingWeights: ['vector' => 0.5, 'lexical' => 1.0],
                evidencePolicy: new HybridEvidencePolicy(),
                lexicalCandidateLimit: $pool,
            );
            $reports[$depth] = new RetrievalEvaluator(TENANT)->evaluate($retriever, $golden['dataset']);
        }
        $report = $reports[20];
        echo sprintf(
            "%6d %10.2f %11.2f %11.2f %8.2f %8.2f %10.2f %9.2f %10.2f %9.2f ms\n",
            $pool,
            (float) $reports[5]->metric('chunk_recall'),
            (float) $reports[10]->metric('chunk_recall'),
            (float) $reports[20]->metric('chunk_recall'),
            (float) $report->metric('chunk_mrr'),
            (float) $report->metric('chunk_hit_at_1'),
            (float) $report->metric('document_recall'),
            (float) $report->metric('document_mrr'),
            (float) $report->metric('document_hit_at_1'),
            $report->averageLatencyMilliseconds(),
        );
    }
    echo PHP_EOL.'O benchmark mede saturação; ele não altera defaults de produção.'.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Candidate pool live indisponível: '.$exception->getMessage().PHP_EOL);
    fwrite(STDERR, 'Configure embeddings e PostgreSQL/pgvector antes de executar.'.PHP_EOL);
    exit(1);
}
