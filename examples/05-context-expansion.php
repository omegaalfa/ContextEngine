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
$question = 'How does PaymentGatewayService recover from ERR_PAYMENT_1047?';

$env = demo_environment();

demo_print_banner('05-context-expansion.php | Neighbor chunk expansion', $question);

demo_print_stage(1, 'Enable neighbor expansion (before=1, after=1).');
$retriever = new Retriever(
    embeddings: $env['embeddings'],
    store: $env['store'],
    policy: new RetrievalPolicy(limit: 2, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
    queryRewriter: new IdentityQueryRewriter(),
    neighborExpansion: new NeighborExpansion(1, 1),
    fusedLimit: 2,
    contextChunkLimit: 4,
    lexicalStore: null,
);

demo_print_stage(2, 'Run retrieval and inspect neighbor chunks added by position.');
$outcome = $retriever->retrieveWithDiagnostics(new Question($question, $env['tenant']));

echo 'Neighbor chunk ids: ';
echo $outcome->diagnostics->neighborChunkIds === []
    ? 'none' . PHP_EOL
    : implode(', ', $outcome->diagnostics->neighborChunkIds) . PHP_EOL;

demo_print_stage(3, 'Show selected chunks with neighbor marker when applicable.');
demo_print_results($outcome->results, 6);

demo_print_stage(4, 'Summarize execution metrics.');
demo_print_timings($outcome->diagnostics);
demo_print_footer($started, demo_unique_document_count($outcome->results), count($outcome->results));
