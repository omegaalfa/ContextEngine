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
$question = 'Show how reserveSkuQuantity() handles SKU AX9-RED and low stock events.';

$env = demo_environment();

demo_print_banner('03-multi-query.php | Heuristic multi-query planning', $question);

demo_print_stage(1, 'Build retriever with heuristic query planner enabled.');
$retriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: new RetrievalPolicy(limit: 4, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new HeuristicQueryRewriter(4),
    neighborExpansion: new NeighborExpansion(0, 0),
    fusedLimit: 4,
    contextChunkLimit: 4,
    lexicalStore: null,
);

demo_print_stage(2, 'Run multi-query retrieval and inspect planned queries.');
$outcome = $retriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));

echo 'Generated queries:' . PHP_EOL;
foreach ($outcome->diagnostics->queries as $index => $queryText) {
    echo '  - Q' . ($index + 1) . ': ' . $queryText . PHP_EOL;
}

demo_print_stage(3, 'Print results produced by each generated query.');
foreach ($outcome->diagnostics->queries as $queryText) {
    $hits = $outcome->diagnostics->resultsByQuery[$queryText] ?? [];
    echo PHP_EOL . 'Query: ' . $queryText . PHP_EOL;
    if ($hits === []) {
        echo '  No hits.' . PHP_EOL;
        continue;
    }
    foreach ($hits as $hit) {
        echo '  rank=' . $hit->rank
            . ' chunk=' . $hit->chunkId
            . ' doc=' . $hit->documentId
            . ' distance=' . number_format($hit->distance, 5, '.', '')
            . PHP_EOL;
    }
}

demo_print_stage(4, 'Print fused final ranking after combining all query results.');
demo_print_results($outcome->results, 4);
demo_print_timings($outcome->diagnostics);
demo_print_footer($started, demo_unique_document_count($outcome->results), count($outcome->results));
