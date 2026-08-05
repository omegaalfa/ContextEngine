<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'How should ERR_PAYMENT_1047 be handled in PaymentGatewayService?';

$env = demo_environment();

demo_print_banner('02-lexical-search.php | Lexical search only', $question);

demo_print_stage(1, 'Prepare lexical query with exact identifiers and plain terms.');
$query = new LexicalSearchQuery(
    tenantId: $env['tenant'],
    terms: $question,
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: $env['collection'],
    status: 'active',
);

demo_print_stage(2, 'Execute lexical retrieval directly in the lexical store.');
$results = $env['store']->searchLexical($query);

demo_print_stage(3, 'Display lexical ranking and pseudo-distance values.');
demo_print_results($results, 5);

demo_print_stage(4, 'Summarize timing, document count, and selected chunks.');
$documents = demo_unique_document_count($results);
$chunks = count($results);
demo_print_footer($started, $documents, $chunks);
