<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'Give code-level guidance for ERR_PAYMENT_1047 in chargeOrder() and renewToken().';

$env = demo_environment();

demo_print_banner('07-diagnostics.php | Full retrieval diagnostics', $question);

demo_print_stage(1, 'Build advanced retriever with planner, hybrid search, and neighbor expansion.');
$retriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new HeuristicQueryRewriter(4),
    neighborExpansion: new NeighborExpansion(1, 1),
    fusedLimit: 5,
    contextChunkLimit: 4,
    maximumContextCharacters: 1500,
    contextRelevancePolicy: new ContextRelevancePolicy(
        maximumDistanceGap: 0.10,
        minimumSources: 1,
        maximumSources: 4,
        preferSameDocument: true,
    ),
    lexicalStore: $env['store'],
);

demo_print_stage(2, 'Execute retrieval and keep complete diagnostics object.');
$outcome = $retriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));
$diagnostics = $outcome->diagnostics;

demo_print_stage(3, 'Print all diagnostics sections.');
echo 'Original question: ' . $diagnostics->originalQuestion . PHP_EOL;
echo 'Queries: ' . implode(' | ', $diagnostics->queries) . PHP_EOL;

echo PHP_EOL . 'Hits per query:' . PHP_EOL;
foreach ($diagnostics->hitsPerQuery as $query => $hits) {
    echo '  - ' . $query . ': ' . $hits . PHP_EOL;
}

echo PHP_EOL . 'Results by query:' . PHP_EOL;
foreach ($diagnostics->resultsByQuery as $query => $rows) {
    echo '  Query: ' . $query . PHP_EOL;
    foreach ($rows as $row) {
        echo '    rank=' . $row->rank
            . ' chunk=' . $row->chunkId
            . ' doc=' . $row->documentId
            . ' distance=' . number_format($row->distance, 5, '.', '')
            . PHP_EOL;
    }
}

echo PHP_EOL . 'Removed by deduplication: ' . $diagnostics->deduplicatedResults . PHP_EOL;
echo 'Fused chunk ids: ' . implode(', ', $diagnostics->fusedChunkIds) . PHP_EOL;
echo 'Neighbor chunk ids: ' . implode(', ', $diagnostics->neighborChunkIds) . PHP_EOL;
echo 'Selected chunk ids: ' . implode(', ', $diagnostics->selectedChunkIds) . PHP_EOL;
echo 'Discarded by budget: ' . implode(', ', $diagnostics->discardedByBudgetChunkIds) . PHP_EOL;
echo 'Context characters: ' . $diagnostics->contextCharacters . PHP_EOL;

if ($diagnostics->contextSelection !== []) {
    echo PHP_EOL . 'Context selection decisions:' . PHP_EOL;
    foreach ($diagnostics->contextSelection as $decision) {
        echo '  - chunk=' . $decision->chunkId
            . ' selected=' . ($decision->selected ? 'yes' : 'no')
            . ' reason=' . $decision->reason->value
            . PHP_EOL;
    }
}

demo_print_stage(4, 'Print final selected chunks and timing summary.');
demo_print_results($outcome->results, 6);
demo_print_timings($diagnostics);
demo_print_footer($started, demo_unique_document_count($outcome->results), count($outcome->results));
