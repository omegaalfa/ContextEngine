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
    $question = 'How should I handle ERR_PAYMENT_1047 safely?';
}

$env = demo_environment();
$model = new DemoLanguageModel();

/*
 * ask(...) mode:
 * - retrieves contextual chunks
 * - builds the prompt
 * - returns one complete final answer string
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

$answer = $rag->ask(new Question($question, $env['tenant']));

demo_print_banner('09-ask.php | ask(...) complete final response', $question);
echo PHP_EOL . 'Final answer:' . PHP_EOL;
echo $answer->content . PHP_EOL;

echo PHP_EOL . 'Selected sources:' . PHP_EOL;
demo_print_results($answer->sources, 6);

demo_print_footer($started, demo_unique_document_count($answer->sources), count($answer->sources));
