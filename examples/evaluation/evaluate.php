<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Evaluation\EvaluationDatasetLoader;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$environment = demo_environment();
$dataset = new EvaluationDatasetLoader()->fromFile(__DIR__.'/dataset.json', 'Algorithms');
$retriever = new Retriever(
    embeddings: $environment['embeddings'],
    store: $environment['store'],
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: $environment['collection'],
    queryRewriter: new HeuristicQueryRewriter(3),
    neighborExpansion: new NeighborExpansion(1, 1),
    fusedLimit: 5,
    contextChunkLimit: 4,
    maximumContextCharacters: 1_400,
    contextRelevancePolicy: new ContextRelevancePolicy(0.10, 1, 4, true),
    lexicalStore: $environment['store'],
);
$pipeline = new RagPipeline($retriever, new ContextPromptBuilder(), new DemoLanguageModel());
$report = new RagEvaluator($environment['tenant'])->evaluate($pipeline, $dataset);

echo PHP_EOL.str_repeat('=', 58).PHP_EOL;
echo '  ContextEngine Evaluation'.PHP_EOL;
echo str_repeat('=', 58).PHP_EOL.PHP_EOL;
echo "Dataset: {$report->datasetName}".PHP_EOL;
echo "Casos:   {$report->executedCases}".PHP_EOL.PHP_EOL;

$metric = static function (string $label, ?float $value, bool $percent = false): void {
    $display = $value === null ? 'n/a' : ($percent ? number_format($value * 100, 1). '%' : number_format($value, 2));
    echo str_pad($label.' ', 28, '.').' '.$display.PHP_EOL;
};
$metric('Recall', $report->averageRecall);
$metric('Precision', $report->averagePrecision);
$metric('MRR', $report->meanReciprocalRank);
$metric('Hit Rate', $report->hitRate, true);
echo str_pad('Tempo médio ', 28, '.').' '.number_format($report->averageTimeMilliseconds, 2).' ms'.PHP_EOL;
echo str_pad('Chunks selecionados ', 28, '.').' '.$report->selectedChunks.PHP_EOL;
echo PHP_EOL.str_repeat('-', 58).PHP_EOL.PHP_EOL;

foreach ($report->results as $result) {
    echo ($result->passed ? '✔' : '✘')." {$result->case->id}";
    if ($result->error !== null) {
        echo " — {$result->error}";
    }
    echo PHP_EOL;
}

echo PHP_EOL.str_repeat('-', 58).PHP_EOL;
echo "Resultado final: {$report->passedCases}/{$report->executedCases} casos aprovados".PHP_EOL.PHP_EOL;
