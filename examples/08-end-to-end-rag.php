<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$started = hrtime(true);
$question = 'Write robust pseudo-code to handle ERR_PAYMENT_1047 and preserve retry safety.';

$env = demo_environment();
$model = new DemoLanguageModel();

demo_print_banner('08-end-to-end-rag.php | Full RAG pipeline + ask() and stream()', $question);

demo_print_stage(1, 'Compose retriever (planning -> retrieval -> RRF -> expansion -> selection).');
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
    maximumContextCharacters: 1400,
    contextRelevancePolicy: new ContextRelevancePolicy(0.10, 1, 4, true),
    lexicalStore: $env['store'],
);

$rag = new RagPipeline(
    retriever: $retriever,
    prompts: new ContextPromptBuilder(),
    model: $model,
    streamingModel: $model,
);

demo_print_stage(2, 'Run askWithDiagnostics() for complete buffered answer.');
$execution = $rag->askWithDiagnostics(new Question($question, $env['tenant']));

echo 'Buffered answer (ask):' . PHP_EOL;
echo $execution->answer->content . PHP_EOL;

echo PHP_EOL . 'Sources used by ask():' . PHP_EOL;
demo_print_results($execution->answer->sources, 6);

demo_print_stage(3, 'Run stream() to consume incremental answer deltas.');
echo 'Streaming answer (stream):' . PHP_EOL;
$streamedText = '';
foreach ($rag->stream(new Question($question, $env['tenant'])) as $delta) {
    if ($delta->final) {
        echo PHP_EOL . '[final marker]' . PHP_EOL;
        break;
    }
    $streamedText .= $delta->content;
    echo '  delta#' . $delta->sequence . ': ' . $delta->content . PHP_EOL;
}

echo PHP_EOL . 'Reconstructed streamed text: ' . $streamedText . PHP_EOL;

demo_print_stage(4, 'Print diagnostics and execution metrics from the full pipeline.');
demo_print_timings($execution->diagnostics->retrieval);
echo 'RAG timings:' . PHP_EOL;
foreach ($execution->diagnostics->timingsMilliseconds as $stage => $ms) {
    echo '  - ' . $stage . ': ' . number_format($ms, 2, '.', '') . ' ms' . PHP_EOL;
}
demo_print_footer($started, demo_unique_document_count($execution->answer->sources), count($execution->answer->sources));
