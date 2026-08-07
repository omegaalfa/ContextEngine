<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/_support/retrieval_demo.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Evaluation\EvaluationStatus;
use Omegaalfa\ContextEngine\Evaluation\RetrievalEvaluator;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

const TENANT = 'evaluation-algorithms';
const COLLECTION = 'examples-documents';
const TOP_K = 5;

$golden = algorithms_golden(TENANT, COLLECTION);
$embeddings = new DemoEmbeddingProvider();
$store = new DemoInMemoryStore();
foreach ($golden['chunks'] as $chunk) {
    $store->storeBatch([new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, TENANT))]);
}
$retriever = new Retriever(
    embeddings: $embeddings,
    store: $store,
    policy: new RetrievalPolicy(limit: TOP_K, maximumDistance: 0.70, metric: VectorMetric::COSINE),
    collection: COLLECTION,
    queryRewriter: new HeuristicQueryRewriter(3),
    neighborExpansion: new NeighborExpansion(),
    fusedLimit: TOP_K,
    contextChunkLimit: TOP_K,
    maximumContextCharacters: 4_000,
    lexicalStore: $store,
    rankingWeights: ['vector' => 0.5, 'lexical' => 1.0],
);

echo PHP_EOL.'Golden dataset validation'.PHP_EOL;
foreach ($golden['dataset'] as $case) {
    $count = $case->expectNoEvidence ? 0 : count($case->relevantChunkIds);
    echo str_pad($case->id.' ', 28, '.').' '.$count.' chunk'.($count === 1 ? '' : 's').' relevante'.($count === 1 ? '' : 's').PHP_EOL;
}

$report = new RetrievalEvaluator(TENANT)->evaluate($retriever, $golden['dataset']);
$metric = static function (string $label, string $name) use ($report): void {
    $value = $report->metric($name);
    $display = $value === null ? 'n/a' : number_format($value, 2);
    echo str_pad($label.' ', 29, '.').' '.$display.' ('.$report->denominator($name).' casos)'.PHP_EOL;
};

echo PHP_EOL.str_repeat('=', 66).PHP_EOL;
echo '  ContextEngine Retrieval Evaluation'.PHP_EOL;
echo str_repeat('=', 66).PHP_EOL.PHP_EOL;
echo 'Mode: offline / deterministic'.PHP_EOL;
echo 'Corpus: examples/documents'.PHP_EOL;
echo 'Main benchmark: hybridSearch = true'.PHP_EOL;
echo 'RRF weights: vector = 0.5 | lexical = 1.0'.PHP_EOL;
echo 'Distance-based relevance: disabled'.PHP_EOL;
echo 'Neighbor expansion: disabled'.PHP_EOL;
echo 'Embedding: DemoEmbeddingProvider (diagnostic only; not representative of real embeddings)'.PHP_EOL;
echo 'Documents: '.count($golden['documents']).PHP_EOL;
echo 'Chunks: '.count($golden['chunks']).PHP_EOL;
echo 'Cases: '.count($golden['dataset']).PHP_EOL;
echo 'Positive cases: '.$report->positiveCases().PHP_EOL;
echo 'Negative cases: '.$report->negativeCases().PHP_EOL.PHP_EOL;
$metric('Chunk Recall@'.TOP_K, 'chunk_recall');
$metric('Chunk Precision@'.TOP_K, 'chunk_precision');
$metric('Chunk MRR', 'chunk_mrr');
$metric('Document Recall@'.TOP_K, 'document_recall');
$metric('Document Precision@'.TOP_K, 'document_precision');
$metric('Document MRR', 'document_mrr');
$metric('Negative abstention', 'no_evidence');
echo str_pad('Latência média ', 29, '.').' '.number_format($report->averageLatencyMilliseconds(), 2).' ms'.PHP_EOL;
echo str_pad('Tempo total ', 29, '.').' '.number_format($report->totalTimeMilliseconds / 1_000, 3).' s'.PHP_EOL;
echo PHP_EOL.str_repeat('-', 66).PHP_EOL;

foreach ($report->results as $result) {
    $symbol = match ($result->status) {
        EvaluationStatus::PASSED => '✔',
        EvaluationStatus::FAILED => '✘',
        EvaluationStatus::ERROR => '!',
        EvaluationStatus::NOT_APPLICABLE => '–',
    };
    echo PHP_EOL.$symbol.' '.$result->case->id.' ['.strtoupper($result->status->value).']'.PHP_EOL;
    echo '  Tempo: '.number_format($result->durationMilliseconds, 2).' ms'.PHP_EOL;
    if ($result->outcome === null) {
        echo '  Erro: '.($result->error ?? 'resultado indisponível').PHP_EOL;
        continue;
    }
    $retrievedChunks = array_map(static fn ($item): string => $item->chunk->id, $result->outcome->results);
    $retrievedDocuments = array_values(array_unique(array_map(static fn ($item): string => $item->chunk->documentId, $result->outcome->results)));
    $expectedHits = array_values(array_intersect($retrievedChunks, $result->case->relevantChunkIds));
    $firstRelevant = null;
    foreach ($retrievedChunks as $offset => $id) {
        if (in_array($id, $result->case->relevantChunkIds, true)) {
            $firstRelevant = $offset + 1;
            break;
        }
    }
    echo '  Top-k recuperado: '.count($retrievedChunks).PHP_EOL;
    echo '  Chunks esperados: '.($result->case->relevantChunkIds === [] ? 'n/a' : implode(', ', $result->case->relevantChunkIds)).PHP_EOL;
    echo '  Chunks encontrados: '.($expectedHits === [] ? 'nenhum' : implode(', ', $expectedHits)).PHP_EOL;
    echo '  Documentos esperados: '.($result->case->relevantDocumentIds === [] ? 'n/a' : implode(', ', $result->case->relevantDocumentIds)).PHP_EOL;
    echo '  Documentos recuperados: '.($retrievedDocuments === [] ? 'nenhum' : implode(', ', $retrievedDocuments)).PHP_EOL;
    echo '  Primeiro relevante: '.($firstRelevant === null ? 'não encontrado' : 'posição '.$firstRelevant).PHP_EOL;
    foreach ($result->scores as $score) {
        echo sprintf('  %-27s %.2f %s', $score->name, $score->value, $score->passed ? '✔' : '✘').PHP_EOL;
    }
    if ($result->status === EvaluationStatus::FAILED) {
        echo $result->case->expectNoEvidence
            ? '  Motivo: contexto indevido foi recuperado para um caso negativo.'.PHP_EOL
            : '  Motivo: '.count($expectedHits).' de '.count($result->case->relevantChunkIds).' chunks relevantes recuperados.'.PHP_EOL;
    }
}

echo PHP_EOL.str_repeat('-', 66).PHP_EOL.PHP_EOL;
echo 'Resumo'.PHP_EOL;
echo 'PASSED: '.$report->count(EvaluationStatus::PASSED).PHP_EOL;
echo 'FAILED: '.$report->count(EvaluationStatus::FAILED).PHP_EOL;
echo 'ERROR: '.$report->count(EvaluationStatus::ERROR).PHP_EOL;
echo 'NOT_APPLICABLE: '.$report->count(EvaluationStatus::NOT_APPLICABLE).PHP_EOL.PHP_EOL;
