<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/_support/retrieval_demo.php';

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\EvaluationResult;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Loader\TextFileGranularity;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

const TENANT = 'evaluation-algorithms';
const COLLECTION = 'examples-documents';

$embeddings = new DemoEmbeddingProvider();
$store = new DemoInMemoryStore();
$splitter = new StructuralTextSplitter(new CharacterLimitStrategy(700));
$documents = [];
$chunks = [];

foreach (glob(dirname(__DIR__).'/documents/*.txt') ?: [] as $path) {
    $loader = new TextFileLoader(
        path: $path,
        tenantId: TENANT,
        collection: COLLECTION,
        granularity: TextFileGranularity::WHOLE_FILE,
        metadata: ['filename' => basename($path)],
    );
    foreach ($loader->load() as $document) {
        $documents[basename($path)] = $document->id;
        foreach ($splitter->split($document) as $chunk) {
            $chunks[] = $chunk;
            $store->storeBatch([new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, TENANT))]);
        }
    }
}

if ($chunks === []) {
    fwrite(STDERR, 'Nenhum documento foi carregado de examples/documents.'.PHP_EOL);
    exit(1);
}

$optimalDocument = $documents['optimal-bst-python.txt'] ?? throw new RuntimeException('Documento Optimal BST não encontrado.');
$chunkIds = static function (string ...$terms) use ($chunks): array {
    return array_values(array_map(
        static fn (Chunk $chunk): string => $chunk->id,
        array_filter(
            $chunks,
            static function (Chunk $chunk) use ($terms): bool {
                $content = mb_strtolower($chunk->content);
                return array_any($terms, static fn (string $term): bool => str_contains($content, mb_strtolower($term)));
            },
        ),
    ));
};

$dataset = new EvaluationDataset([
    new EvaluationCase('Dijkstra', 'Como funciona o algoritmo de Dijkstra?', expectedTerms: ['Dijkstra', 'menor caminho']),
    new EvaluationCase('Bellman-Ford', 'Qual a complexidade do Bellman-Ford?', expectedTerms: ['Bellman-Ford', 'complexidade']),
    new EvaluationCase('Quicksort', 'Explique o algoritmo Quicksort.', expectedTerms: ['Quicksort', 'pivô']),
    new EvaluationCase(
        'Programação dinâmica',
        'O que é programação dinâmica?',
        relevantChunkIds: $chunkIds('preenchimento por comprimentos crescentes'),
        relevantDocumentIds: [$optimalDocument],
        expectedTerms: ['subproblema'],
    ),
    new EvaluationCase('Floyd-Warshall', 'Para que serve Floyd-Warshall?', expectedTerms: ['Floyd-Warshall', 'caminhos mínimos']),
    new EvaluationCase('Merge Sort', 'O que é Merge Sort?', expectedTerms: ['Merge Sort', 'intercalação']),
    new EvaluationCase('Heap Sort', 'Como funciona Heap Sort?', expectedTerms: ['Heap Sort', 'heap']),
    new EvaluationCase('Árvore AVL', 'O que é uma árvore AVL?', expectedTerms: ['AVL', 'balanceada']),
    new EvaluationCase(
        'Tabela root',
        'Explique a tabela root do Optimal BST.',
        relevantChunkIds: $chunkIds('A tabela `root` informa'),
        relevantDocumentIds: [$optimalDocument],
        expectedTerms: ['root', 'raiz', 'intervalo'],
    ),
    new EvaluationCase(
        'Tabela w',
        'O que representa a tabela w no Optimal BST?',
        expectedAnswer: 'A tabela w contém a soma das probabilidades de cada subproblema do Optimal BST.',
        relevantChunkIds: $chunkIds('w[i][j]: soma das probabilidades'),
        relevantDocumentIds: [$optimalDocument],
        expectedTerms: ['probabilidades', 'subproblema'],
    ),
], 'Algorithms');

$retriever = new Retriever(
    embeddings: $embeddings,
    store: $store,
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: COLLECTION,
    queryRewriter: new HeuristicQueryRewriter(3),
    neighborExpansion: new NeighborExpansion(1, 1),
    fusedLimit: 5,
    contextChunkLimit: 5,
    maximumContextCharacters: 3_500,
    contextRelevancePolicy: new ContextRelevancePolicy(0.05, 1, 5, true),
    lexicalStore: $store,
);
$model = new class () implements LanguageModel {
    public function complete(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof ChatMessage) {
                preg_match('/<CONTEXT[^>]*>(.*?)<\/CONTEXT>/s', $message->content, $matches);
                return isset($matches[1]) ? trim($matches[1]) : '';
            }
        }
        return '';
    }
};
$pipeline = new RagPipeline($retriever, new ContextPromptBuilder(), $model);
$report = new RagEvaluator(TENANT)->evaluate($pipeline, $dataset);

$averageScore = static function (string $name) use ($report): ?float {
    $values = [];
    foreach ($report->results as $result) {
        if (isset($result->scores[$name])) {
            $values[] = $result->scores[$name]->value;
        }
    }
    return $values === [] ? null : array_sum($values) / count($values);
};
$metric = static function (string $label, ?float $value, bool $percentage = false): void {
    $formatted = $value === null
        ? 'n/a'
        : ($percentage ? number_format($value * 100, 1). '%' : number_format($value, 2));
    echo str_pad($label.' ', 28, '.').' '.$formatted.PHP_EOL;
};

echo PHP_EOL.str_repeat('=', 58).PHP_EOL;
echo '  ContextEngine Evaluation'.PHP_EOL;
echo str_repeat('=', 58).PHP_EOL.PHP_EOL;
echo "Dataset: {$report->datasetName}".PHP_EOL;
echo "Casos executados: {$report->executedCases}".PHP_EOL;
echo 'Documentos indexados: '.count($documents).PHP_EOL;
echo 'Chunks indexados: '.count($chunks).PHP_EOL.PHP_EOL;
$metric('Recall@5', $report->averageRecall);
$metric('Precision@5', $report->averagePrecision);
$metric('MRR', $report->meanReciprocalRank);
$metric('Hit Rate', $report->hitRate, true);
$metric('Exact Match', $averageScore('exact_match'), true);
$metric('Expected Terms', $averageScore('contains_expected_terms'), true);
echo str_pad('Tempo médio ', 28, '.').' '.number_format($report->averageTimeMilliseconds, 2).' ms'.PHP_EOL;
echo str_pad('Tempo total ', 28, '.').' '.number_format($report->totalTimeMilliseconds / 1_000, 2).' s'.PHP_EOL;
echo PHP_EOL.str_repeat('-', 58).PHP_EOL;

foreach ($report->results as $result) {
    echo PHP_EOL.($result->passed ? '✔' : '✘')." {$result->case->id}".PHP_EOL;
    echo '  Tempo: '.number_format($result->durationMilliseconds, 2).' ms'.PHP_EOL;
    if ($result->execution === null) {
        echo '  Erro: '.($result->error ?? 'execução indisponível').PHP_EOL;
        continue;
    }
    $diagnostics = $result->execution->diagnostics->retrieval;
    $documentIds = array_values(array_unique(array_map(
        static fn ($source): string => $source->chunk->documentId,
        $result->execution->answer->sources,
    )));
    $documentNames = array_map(
        static fn (string $id): string => array_search($id, $documents, true) ?: $id,
        $documentIds,
    );
    echo '  Chunks recuperados: '.count($diagnostics->fusedChunkIds).PHP_EOL;
    echo '  Chunks selecionados: '.count($diagnostics->selectedChunkIds).PHP_EOL;
    echo '  Documentos utilizados: '.($documentNames === [] ? 'nenhum' : implode(', ', $documentNames)).PHP_EOL;
    if ($result->scores === []) {
        echo '  Métricas: nenhuma expectativa configurada.'.PHP_EOL;
    } else {
        echo '  Métricas:'.PHP_EOL;
        foreach ($result->scores as $score) {
            echo sprintf('    %-25s %.2f %s', $score->name, $score->value, $score->passed ? '✔' : '✘').PHP_EOL;
        }
    }
    if (!$result->passed && ($result->retrieval->hitRate ?? 1.0) === 0.0) {
        echo '  Motivo: nenhum chunk esperado recuperado.'.PHP_EOL;
    }
}

echo PHP_EOL.str_repeat('-', 58).PHP_EOL.PHP_EOL;
echo 'Resumo'.PHP_EOL.PHP_EOL;
echo "{$report->passedCases}/{$report->executedCases} casos aprovados".PHP_EOL;
echo 'Use a mesma base golden para comparar parser, chunking, retrieval, reranker ou embeddings.'.PHP_EOL.PHP_EOL;
