<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'Explain calculateInvoiceTotal() and refund behavior for duplicate charge evidence.';

$env = demo_environment();

demo_print_banner('04-rrf.php | Reciprocal Rank Fusion view', $question);

demo_print_stage(1, 'Enable multi-query retrieval so RRF receives multiple rankings.');
$retriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new HeuristicQueryRewriter(4),
    neighborExpansion: new NeighborExpansion(0, 0),
    fusedLimit: 5,
    contextChunkLimit: 5,
    lexicalStore: null,
);

demo_print_stage(2, 'Run retrieval and print individual rankings before fusion.');
$outcome = $retriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));
foreach ($outcome->diagnostics->resultsByQuery as $query => $results) {
    echo PHP_EOL . 'Ranking for query: ' . $query . PHP_EOL;
    foreach ($results as $row) {
        echo '  rank=' . $row->rank
            . ' chunk=' . $row->chunkId
            . ' distance=' . number_format($row->distance, 5, '.', '')
            . PHP_EOL;
    }
}

demo_print_stage(3, 'Show final fused ranking after Reciprocal Rank Fusion.');
demo_print_results($outcome->results, 5);

echo PHP_EOL . 'Fused chunk ids (diagnostics): ' . implode(', ', $outcome->diagnostics->fusedChunkIds) . PHP_EOL;

demo_print_stage(4, 'Summarize timing, documents found, and chunks selected.');
demo_print_timings($outcome->diagnostics);
demo_print_footer($started, demo_unique_document_count($outcome->results), count($outcome->results));
