<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/_support/retrieval_demo.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\QueryResultDiagnostic;
use Omegaalfa\ContextEngine\Retrieval\RetrievalDiagnostics;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

const TENANT = 'evaluation-algorithms';
const COLLECTION = 'examples-documents';
const LEXICAL_KEY = '__lexical__';

$golden = algorithms_golden(TENANT, COLLECTION);
$embeddings = new DemoEmbeddingProvider();
$store = new DemoInMemoryStore();
foreach ($golden['chunks'] as $chunk) {
    $store->storeBatch([new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, TENANT))]);
}

$retriever = static function (
    string $mode,
    int $limit,
    bool $heuristic,
    bool $relevance,
    bool $expansion,
    float $vectorWeight = 1.0,
    float $lexicalWeight = 1.0,
) use ($embeddings, $store): Retriever {
    $vectorStore = $mode === 'lexical' ? new class () implements VectorStore {
        public function storeBatch(array $chunks): void {}
        public function search(VectorSearchQuery $query): array
        {
            return [];
        }
        public function deleteChunk(ChunkDeleteQuery $query): int
        {
            return 0;
        }
        public function deleteDocument(DocumentDeleteQuery $query): int
        {
            return 0;
        }
        public function clearCollection(CollectionDeleteQuery $query): int
        {
            return 0;
        }
    } : $store;
    return new Retriever(
        embeddings: $embeddings,
        store: $vectorStore,
        policy: new RetrievalPolicy(limit: $limit, maximumDistance: null, metric: VectorMetric::COSINE),
        collection: COLLECTION,
        queryRewriter: $heuristic ? new HeuristicQueryRewriter(3) : new IdentityQueryRewriter(),
        neighborExpansion: $expansion ? new NeighborExpansion(1, 1) : new NeighborExpansion(),
        fusedLimit: $limit,
        contextChunkLimit: $limit,
        maximumContextCharacters: null,
        contextRelevancePolicy: $relevance ? new ContextRelevancePolicy(0.05, 1, $limit, true) : null,
        lexicalStore: $mode === 'vector' ? null : $store,
        rankingWeights: ['vector' => $vectorWeight, 'lexical' => $lexicalWeight],
    );
};

echo PHP_EOL.str_repeat('=', 78).PHP_EOL;
echo '  ContextEngine Retrieval Stage Diagnostics'.PHP_EOL;
echo str_repeat('=', 78).PHP_EOL;
echo 'Corpus: examples/documents | Documents: '.count($golden['documents']).' | Chunks: '.count($golden['chunks']).PHP_EOL;
echo 'Mode: offline / experimental diagnostics'.PHP_EOL;
echo 'Embedding: DemoEmbeddingProvider (weak baseline; do not extrapolate to bge-m3)'.PHP_EOL;
echo 'Detailed pipeline below: hybrid + heuristic + weighted RRF + distance relevance ENABLED'.PHP_EOL;
echo 'This is an experimental ablation, not the main benchmark configuration.'.PHP_EOL;
echo 'Ordem: raw → RRF → relevance dos anchors → expansão → seleção final.'.PHP_EOL;

$diagnosticRetriever = $retriever('hybrid', 20, true, true, true, 0.5, 1.0);
$positiveBest = [];
$negativeBest = [];
foreach ($golden['dataset'] as $case) {
    $outcome = $diagnosticRetriever->retrieveWithDiagnostics(new Question($case->question, TENANT));
    $diagnostics = $outcome->diagnostics;
    echo PHP_EOL.str_repeat('-', 78).PHP_EOL;
    echo $case->id.' | '.($case->expectNoEvidence ? 'NEGATIVE' : 'POSITIVE').PHP_EOL;
    echo 'Esperados: '.($case->relevantChunkIds === [] ? 'nenhum contexto' : implode(', ', array_map(short_id(...), $case->relevantChunkIds))).PHP_EOL;

    $vector = [];
    foreach ($diagnostics->resultsByQuery as $query => $ranking) {
        if ($query !== LEXICAL_KEY) {
            foreach ($ranking as $item) {
                $vector[$item->chunkId] ??= $item;
            }
        }
    }
    $lexical = $diagnostics->resultsByQuery[LEXICAL_KEY] ?? [];
    print_raw_stage('Vetorial bruto top 20', array_values($vector), $case);
    print_raw_stage('Lexical bruto top 20', $lexical, $case);
    print_id_stage('Após RRF top 20', $diagnostics->fusedChunkIds, $case);
    print_id_stage('Após relevance policy', $diagnostics->relevanceSelectedChunkIds, $case);
    print_id_stage('Após expansão', $diagnostics->expandedChunkIds, $case);
    print_id_stage('Seleção final', $diagnostics->selectedChunkIds, $case);

    foreach ($case->relevantChunkIds as $expectedId) {
        $decision = array_values(array_filter(
            $diagnostics->contextSelection,
            static fn ($item): bool => $item->chunkId === $expectedId,
        ))[0] ?? null;
        if ($decision !== null && !$decision->selected) {
            echo '  Remoção de '.short_id($expectedId).': '.$decision->reason->value.PHP_EOL;
        }
    }
    $bestDistance = best_distance($diagnostics);
    if ($bestDistance !== null) {
        if ($case->expectNoEvidence || $case->relevantChunkIds === []) {
            $negativeBest[] = $bestDistance;
        } else {
            $positiveBest[] = $bestDistance;
        }
        echo '  Melhor distância bruta: '.number_format($bestDistance, 4).PHP_EOL;
    }
}

echo PHP_EOL.str_repeat('=', 78).PHP_EOL;
echo '  Recall por profundidade — hybrid bruto, sem cortes'.PHP_EOL;
echo str_repeat('=', 78).PHP_EOL;
$rawRetriever = $retriever('hybrid', 50, false, false, false, 0.5, 1.0);
$depths = [5, 10, 20, 50];
foreach ($depths as $depth) {
    $values = [];
    foreach ($golden['dataset'] as $case) {
        if ($case->expectNoEvidence) {
            continue;
        }
        $ids = $rawRetriever->retrieveWithDiagnostics(new Question($case->question, TENANT))->diagnostics->fusedChunkIds;
        $values[] = recall(array_slice($ids, 0, $depth), $case->relevantChunkIds);
    }
    echo 'Recall@'.$depth.' '.str_repeat('.', max(1, 20 - strlen((string) $depth))).' '.number_format(array_sum($values) / count($values), 2).PHP_EOL;
}

echo PHP_EOL.str_repeat('=', 78).PHP_EOL;
echo '  Ablation matrix — Chunk Recall@20'.PHP_EOL;
echo str_repeat('=', 78).PHP_EOL;
echo 'Only variants D and E enable the experimental distance-based relevance policy.'.PHP_EOL;
$ablations = [
    'A. vector only / identity / raw' => ['vector', 20, false, false, false],
    'B. lexical only / identity / raw' => ['lexical', 20, false, false, false],
    'C. hybrid + RRF / identity / raw' => ['hybrid', 20, false, false, false],
    'D. hybrid identity + relevance' => ['hybrid', 20, false, true, false],
    'E. hybrid heuristic + relevance' => ['hybrid', 20, true, true, false],
    'F. hybrid heuristic / no relevance' => ['hybrid', 20, true, false, false],
    'G. hybrid heuristic / limit 50' => ['hybrid', 50, true, false, false],
    'H. hybrid weighted 0.5/1.0' => ['hybrid', 20, true, false, false, 0.5, 1.0],
];
foreach ($ablations as $label => $configuration) {
    $candidate = $retriever(...$configuration);
    $values = [];
    foreach ($golden['dataset'] as $case) {
        if (!$case->expectNoEvidence) {
            $ids = $candidate->retrieveWithDiagnostics(new Question($case->question, TENANT))->diagnostics->selectedChunkIds;
            $values[] = recall(array_slice($ids, 0, 20), $case->relevantChunkIds);
        }
    }
    echo str_pad($label.' ', 45, '.').' '.number_format(array_sum($values) / count($values), 2).PHP_EOL;
}

echo PHP_EOL.str_repeat('=', 78).PHP_EOL;
echo '  Distribuição para futura política de evidência'.PHP_EOL;
echo str_repeat('=', 78).PHP_EOL;
print_distribution('Positivos', $positiveBest);
print_distribution('Negativos', $negativeBest);
echo 'Não escolha threshold apenas por esta amostra; observe sobreposição e valide novamente.'.PHP_EOL.PHP_EOL;

function short_id(string $id): string
{
    return substr($id, 0, 8).'…';
}

/** @param list<QueryResultDiagnostic> $ranking */
function print_raw_stage(string $label, array $ranking, EvaluationCase $case): void
{
    $ids = array_map(static fn (QueryResultDiagnostic $item): string => $item->chunkId, array_slice($ranking, 0, 20));
    print_id_stage($label, $ids, $case);
    foreach ($ranking as $item) {
        if (in_array($item->chunkId, $case->relevantChunkIds, true)) {
            $score = $item->lexicalScore === null ? '' : ' | lexical score '.number_format($item->lexicalScore, 4);
            echo '    '.short_id($item->chunkId).' distância '.number_format($item->distance, 4).$score.PHP_EOL;
        }
    }
}

/** @param list<string> $ids */
function print_id_stage(string $label, array $ids, EvaluationCase $case): void
{
    $positions = [];
    foreach ($case->relevantChunkIds as $expected) {
        $offset = array_search($expected, $ids, true);
        if ($offset !== false) {
            $positions[] = short_id($expected).' na posição '.($offset + 1);
        }
    }
    $status = $case->expectNoEvidence
        ? ($ids === [] ? 'sem candidatos' : count($ids).' candidatos indevidos')
        : ($positions === [] ? 'não apareceu' : implode('; ', $positions));
    echo '  '.str_pad($label.' ', 31, '.').' '.$status.PHP_EOL;
}

function best_distance(RetrievalDiagnostics $diagnostics): ?float
{
    $values = [];
    foreach ($diagnostics->resultsByQuery as $ranking) {
        foreach ($ranking as $item) {
            $values[] = $item->distance;
        }
    }
    return $values === [] ? null : min($values);
}

/** @param list<string> $retrieved @param list<string> $expected */
function recall(array $retrieved, array $expected): float
{
    if ($expected === []) {
        return 0.0;
    }
    return count(array_intersect(array_unique($retrieved), array_unique($expected))) / count(array_unique($expected));
}

/** @param list<float> $values */
function print_distribution(string $label, array $values): void
{
    if ($values === []) {
        echo $label.': n/a'.PHP_EOL;
        return;
    }
    sort($values);
    echo sprintf('%s: min %.4f | média %.4f | max %.4f', $label, min($values), array_sum($values) / count($values), max($values)).PHP_EOL;
}
