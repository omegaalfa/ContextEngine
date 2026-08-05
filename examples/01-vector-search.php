<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'How can I implement refund window validation in PHP?';

$env = demo_environment();

demo_print_banner('01-vector-search.php | Vector search only', $question);

demo_print_stage(1, 'Build retriever with vector search only (no lexical store).');
$retriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new IdentityQueryRewriter(),
    neighborExpansion: new NeighborExpansion(0, 0),
    fusedLimit: 5,
    contextChunkLimit: 5,
    lexicalStore: null,
);

demo_print_stage(2, 'Run retrieval and collect diagnostics.');
$outcome = $retriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));

demo_print_stage(3, 'Show final ranked chunks from vector similarity.');
demo_print_results($outcome->results, 5);

demo_print_stage(4, 'Show pipeline timings and counters.');
$documents = demo_unique_document_count($outcome->results);
$chunks = count($outcome->results);
demo_print_timings($outcome->diagnostics);
demo_print_footer($started, $documents, $chunks);
