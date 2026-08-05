<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Stream an answer about SKU AX9-RED and low stock handling.';
}

$env = demo_environment();
$model = new DemoLanguageModel();

/*
 * stream(...) mode:
 * - retrieves context exactly like ask(...)
 * - returns incremental deltas as they are emitted by the streaming model
 * - emits a final marker delta with final=true at stream completion
 */
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
    lexicalStore: $env['store'],
);

$rag = new RagPipeline(
    retriever: $retriever,
    prompts: new ContextPromptBuilder(),
    model: $model,
    streamingModel: $model,
);

demo_print_banner('10-stream.php | stream(...) incremental deltas', $question);

echo PHP_EOL . 'Deltas:' . PHP_EOL;
$reconstructed = '';
$count = 0;
foreach ($rag->stream(new Question($question, $env['tenant'])) as $delta) {
    if ($delta->final) {
        echo '  [final marker]' . PHP_EOL;
        break;
    }
    ++$count;
    $reconstructed .= $delta->content;
    echo '  delta#' . $delta->sequence . ': ' . $delta->content . PHP_EOL;
}

echo PHP_EOL . 'Reconstructed text:' . PHP_EOL;
echo $reconstructed . PHP_EOL;

echo PHP_EOL . 'Delta chunks received: ' . $count . PHP_EOL;
demo_print_footer($started, 1, $count);
