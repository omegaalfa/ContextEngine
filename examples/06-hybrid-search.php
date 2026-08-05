<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'Where is SKU AX9-RED low stock handled and what event is emitted?';

$env = demo_environment();
$policy = new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE);

demo_print_banner('06-hybrid-search.php | Vector vs lexical vs hybrid', $question);

demo_print_stage(1, 'Run vector-only retrieval baseline.');
$vectorRetriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: $policy,
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new IdentityQueryRewriter(),
    neighborExpansion: new NeighborExpansion(0, 0),
    fusedLimit: 5,
    contextChunkLimit: 5,
    lexicalStore: null,
);
$vectorOutcome = $vectorRetriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));

demo_print_stage(2, 'Run lexical-only retrieval baseline.');
$lexicalResults = $env['store']->searchLexical(new LexicalSearchQuery(
    tenantId: $env['tenant'],
    terms: $question,
    policy: $policy,
    collection: $env['collection'],
    status: 'active',
));

demo_print_stage(3, 'Run hybrid retrieval (vector + lexical fused by RRF).');
$hybridRetriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: $policy,
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new IdentityQueryRewriter(),
    neighborExpansion: new NeighborExpansion(0, 0),
    fusedLimit: 5,
    contextChunkLimit: 5,
    lexicalStore: $env['store'],
);
$hybridOutcome = $hybridRetriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));

demo_print_stage(4, 'Compare top results from each strategy.');
echo PHP_EOL . 'Vector top results:' . PHP_EOL;
demo_print_results($vectorOutcome->results, 3);

echo PHP_EOL . 'Lexical top results:' . PHP_EOL;
demo_print_results($lexicalResults, 3);

echo PHP_EOL . 'Hybrid top results:' . PHP_EOL;
demo_print_results($hybridOutcome->results, 3);

echo PHP_EOL . 'Hybrid queries: ' . implode(', ', $hybridOutcome->diagnostics->queries) . PHP_EOL;
demo_print_timings($hybridOutcome->diagnostics);
demo_print_footer($started, demo_unique_document_count($hybridOutcome->results), count($hybridOutcome->results));
