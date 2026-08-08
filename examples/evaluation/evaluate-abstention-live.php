<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\HybridEvidencePolicy;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

const TENANT = 'evaluation-abstention-live';
const COLLECTION = 'evaluation-abstention-live';

$cases = [
    ['category' => 'positive', 'label' => 'entidade existente', 'query' => 'Dijkstra', 'intent' => 'Dijkstra', 'golden' => 'Dijkstra'],
    ['category' => 'positive', 'label' => 'paráfrase legítima', 'query' => 'Como encontrar caminhos mínimos a partir de um vértice?', 'intent' => 'Dijkstra', 'golden' => 'Dijkstra'],
    ['category' => 'positive', 'label' => 'consulta curta legítima', 'query' => 'O que é uma árvore AVL?', 'intent' => 'Árvore AVL', 'golden' => 'Árvore AVL'],
    ['category' => 'typo', 'label' => 'typo recuperável', 'query' => 'Como funciona Dijsktra?', 'intent' => 'Dijkstra', 'golden' => 'Dijkstra'],
    ['category' => 'typo', 'label' => 'variação sem hífen', 'query' => 'Qual a complexidade de Bellman Ford?', 'intent' => 'Bellman-Ford', 'golden' => 'Bellman-Ford'],
    ['category' => 'typo', 'label' => 'letra ausente', 'query' => 'Como funciona Belman-Ford?', 'intent' => 'Bellman-Ford', 'golden' => 'Bellman-Ford'],
    ['category' => 'typo', 'label' => 'espaço no nome', 'query' => 'Explique Quick sort.', 'intent' => 'Quicksort', 'golden' => 'Quicksort'],
    ['category' => 'typo', 'label' => 'letra ausente', 'query' => 'Explique Quiksort.', 'intent' => 'Quicksort', 'golden' => 'Quicksort'],
    ['category' => 'typo', 'label' => 'troca de letra', 'query' => 'Para que serve Floid-Warshall?', 'intent' => 'Floyd-Warshall', 'golden' => 'Floyd-Warshall'],
    ['category' => 'typo', 'label' => 'acentuação e caixa', 'query' => 'O que é uma arvore AVl?', 'intent' => 'Árvore AVL', 'golden' => 'Árvore AVL'],
    ['category' => 'negative', 'label' => 'consulta inexistente', 'query' => 'Explique QZXPT-481.', 'intent' => null, 'golden' => null],
    ['category' => 'negative', 'label' => 'nome próprio inexistente', 'query' => 'Como funciona o algoritmo Wesley?', 'intent' => null, 'golden' => null],
    ['category' => 'negative', 'label' => 'identificador inexistente', 'query' => 'Explique XYZ-WESLEY-999.', 'intent' => null, 'golden' => null],
    ['category' => 'negative', 'label' => 'semanticamente relacionada sem resposta', 'query' => 'Qual algoritmo foi criado em Marte para ordenar grafos?', 'intent' => null, 'golden' => null],
];

try {
    $golden = algorithms_golden(TENANT, COLLECTION);
    $goldenCases = [];
    foreach ($golden['dataset'] as $case) {
        $goldenCases[$case->id] = $case;
    }
    $context = ContextEngine::create()->tenant(TENANT)->collection(COLLECTION)
        ->ingestion(chunkSize: 700, chunkOverlap: 0)->build();
    foreach ($golden['paths'] as $path) {
        $context->ingest(algorithms_loader($path, TENANT, COLLECTION));
    }
    if (!$context->store instanceof LexicalSearchStore) {
        throw new RuntimeException('O vector store configurado não oferece busca lexical.');
    }
    $retriever = new Retriever(
        embeddings: $context->embeddings,
        store: $context->store,
        policy: new RetrievalPolicy(20, VectorMetric::COSINE, 0.45),
        collection: COLLECTION,
        queryRewriter: new HeuristicQueryRewriter(3),
        neighborExpansion: new NeighborExpansion(),
        fusedLimit: 20,
        contextChunkLimit: 5,
        lexicalStore: $context->store,
        rankingWeights: ['vector' => 0.5, 'lexical' => 1.0],
        evidencePolicy: new HybridEvidencePolicy(),
        lexicalCandidateLimit: 20,
    );

    $counts = ['positive' => 0, 'positiveAccepted' => 0, 'typo' => 0, 'typoAccepted' => 0, 'negative' => 0, 'negativeAbstained' => 0];
    echo PHP_EOL.str_repeat('=', 88).PHP_EOL.'  ContextEngine - Calibração de Abstenção e Typos'.PHP_EOL.str_repeat('=', 88).PHP_EOL;
    foreach ($cases as $case) {
        $outcome = $retriever->retrieveWithDiagnostics(new Question($case['query'], TENANT));
        $diagnostics = $outcome->diagnostics;
        $accepted = $outcome->results !== [];
        ++$counts[$case['category']];
        $counts[$case['category'].($case['category'] === 'negative' ? 'Abstained' : 'Accepted')] += (int) ($case['category'] === 'negative' ? !$accepted : $accepted);

        $expectedIds = $case['golden'] === null ? [] : $goldenCases[$case['golden']]->relevantChunkIds;
        $vectorIds = [];
        foreach ($diagnostics->resultsByQuery as $source => $items) {
            if ($source === '__lexical__') {
                continue;
            }
            array_push($vectorIds, ...array_map(static fn ($item): string => $item->chunkId, $items));
        }
        $lexicalIds = array_map(static fn ($item): string => $item->chunkId, $diagnostics->resultsByQuery['__lexical__'] ?? []);
        $vectorFound = $expectedIds !== [] && array_intersect($expectedIds, $vectorIds) !== [];
        $lexicalFound = $expectedIds !== [] && array_intersect($expectedIds, $lexicalIds) !== [];
        $rrfRank = first_rank($diagnostics->fusedChunkIds, $expectedIds);
        $rerankerRank = first_rank($diagnostics->rerankedChunkIds, $expectedIds);
        $shouldAccept = $case['category'] !== 'negative';
        $passed = $accepted === $shouldAccept;

        echo PHP_EOL.($passed ? '✔' : '✘').' ['.strtoupper($case['category']).'] '.$case['label'].PHP_EOL;
        echo '  Query: '.$case['query'].PHP_EOL;
        echo '  Expected intent: '.($case['intent'] ?? 'nenhuma evidência').PHP_EOL;
        echo '  Vector: '.($expectedIds === [] ? count($vectorIds).' candidatos' : ($vectorFound ? 'encontrado' : 'não encontrado')).PHP_EOL;
        echo '  Lexical: '.($expectedIds === [] ? count($lexicalIds).' candidatos' : ($lexicalFound ? 'encontrado' : 'não encontrado')).PHP_EOL;
        echo '  RRF: '.($rrfRank === null ? 'não apareceu' : 'posição '.$rrfRank).PHP_EOL;
        echo '  Reranker: '.($rerankerRank === null ? 'não apareceu' : 'posição '.$rerankerRank).PHP_EOL;
        echo '  Abstention: '.($accepted ? 'ACCEPT' : 'REJECT').PHP_EOL;
        echo '  Reason: '.($diagnostics->abstentionReason ?? 'não informado').PHP_EOL;
        if (!$passed) {
            echo '  Signals: '.json_encode($diagnostics->abstentionSignals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        }
    }

    $positiveTotal = $counts['positive'] + $counts['typo'];
    $falsePositives = ($counts['positive'] - $counts['positiveAccepted']) + ($counts['typo'] - $counts['typoAccepted']);
    $falseNegatives = $counts['negative'] - $counts['negativeAbstained'];
    echo PHP_EOL.str_repeat('-', 88).PHP_EOL;
    metric('Positive acceptance rate', $counts['positiveAccepted'], $counts['positive']);
    metric('Negative abstention rate', $counts['negativeAbstained'], $counts['negative']);
    metric('Typo recovery rate', $counts['typoAccepted'], $counts['typo']);
    metric('False positive rate', $falsePositives, $positiveTotal);
    metric('False negative rate', $falseNegatives, $counts['negative']);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Abstention live indisponível: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

/** @param list<string> $ranking @param list<string> $expected */
function first_rank(array $ranking, array $expected): ?int
{
    foreach ($ranking as $offset => $id) {
        if (in_array($id, $expected, true)) {
            return $offset + 1;
        }
    }
    return null;
}

function metric(string $label, int $hits, int $total): void
{
    echo str_pad($label.' ', 31, '.').' '.number_format($total === 0 ? 0 : $hits / $total, 2)." ({$hits}/{$total})".PHP_EOL;
}
