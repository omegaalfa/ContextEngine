<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/_support/retrieval_demo.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\Contract\Reranker;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Evaluation\RetrievalEvaluator;
use Omegaalfa\ContextEngine\Retrieval\DeterministicTextualReranker;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

const TENANT = 'evaluation-reranker';
const COLLECTION = 'examples-documents';
const CANDIDATE_LIMIT = 20;
const FINAL_LIMIT = 5;

$golden = algorithms_golden(TENANT, COLLECTION);
$embeddings = new DemoEmbeddingProvider();
$store = new DemoInMemoryStore();
foreach ($golden['chunks'] as $chunk) {
    $store->storeBatch([new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, TENANT))]);
}

$build = static fn (?Reranker $reranker): Retriever => new Retriever(
    embeddings: $embeddings,
    store: $store,
    policy: new RetrievalPolicy(limit: CANDIDATE_LIMIT, maximumDistance: null, metric: VectorMetric::COSINE),
    collection: COLLECTION,
    queryRewriter: new HeuristicQueryRewriter(3),
    neighborExpansion: new NeighborExpansion(),
    fusedLimit: CANDIDATE_LIMIT,
    contextChunkLimit: FINAL_LIMIT,
    maximumContextCharacters: 4_000,
    lexicalStore: $store,
    rankingWeights: ['vector' => 0.5, 'lexical' => 1.0],
    reranker: $reranker,
);

$baseline = new RetrievalEvaluator(TENANT)->evaluate($build(null), $golden['dataset']);
$reranked = new RetrievalEvaluator(TENANT)->evaluate($build(new DeterministicTextualReranker()), $golden['dataset']);

echo PHP_EOL.str_repeat('=', 72).PHP_EOL;
echo '  ContextEngine - Comparação de reranker'.PHP_EOL;
echo str_repeat('=', 72).PHP_EOL;
echo 'Modo: offline e determinístico'.PHP_EOL;
echo 'Candidatos após RRF: '.CANDIDATE_LIMIT.PHP_EOL;
echo 'Chunks finais: '.FINAL_LIMIT.PHP_EOL;
echo 'Reranker: DeterministicTextualReranker (referência, não cross-encoder)'.PHP_EOL.PHP_EOL;

echo sprintf("%-28s %12s %12s %12s\n", 'Métrica', 'Sem reranker', 'Com reranker', 'Diferença');
echo str_repeat('-', 72).PHP_EOL;
foreach ([
    'Recall@5' => 'chunk_recall',
    'Hit@1' => 'chunk_hit_at_1',
    'Precision@5' => 'chunk_precision',
    'MRR' => 'chunk_mrr',
    'Document Recall@5' => 'document_recall',
    'Document Hit@1' => 'document_hit_at_1',
    'Document MRR' => 'document_mrr',
] as $label => $metric) {
    $before = $baseline->metric($metric);
    $after = $reranked->metric($metric);
    echo sprintf(
        "%-28s %12s %12s %+12s\n",
        $label,
        $before === null ? 'n/a' : number_format($before, 2),
        $after === null ? 'n/a' : number_format($after, 2),
        $before === null || $after === null ? 'n/a' : number_format($after - $before, 2),
    );
}
echo sprintf(
    "%-28s %9.2f ms %9.2f ms %+9.2f ms\n",
    'Latência média',
    $baseline->averageLatencyMilliseconds(),
    $reranked->averageLatencyMilliseconds(),
    $reranked->averageLatencyMilliseconds() - $baseline->averageLatencyMilliseconds(),
);

echo PHP_EOL.'Movimentação dos primeiros candidatos relevantes:'.PHP_EOL;
foreach ($reranked->results as $result) {
    if ($result->outcome === null || $result->case->relevantChunkIds === []) {
        continue;
    }
    $movement = array_find(
        $result->outcome->diagnostics->reranking,
        static fn ($item): bool => in_array($item->chunkId, $result->case->relevantChunkIds, true),
    );
    echo str_pad($result->case->id.' ', 30, '.').' '.($movement === null
        ? 'não encontrado nos candidatos'
        : "#{$movement->rankBefore} -> #{$movement->rankAfter} | score ".number_format((float) $movement->rerankerScore, 2)).PHP_EOL;
}

echo PHP_EOL.'Execute novamente com:'.PHP_EOL;
echo 'php examples/evaluation/compare-reranker.php'.PHP_EOL.PHP_EOL;
