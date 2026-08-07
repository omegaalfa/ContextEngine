<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\GoldenChunkMatcher;
use Omegaalfa\ContextEngine\Evaluation\GoldenMatchMode;
use Omegaalfa\ContextEngine\Evaluation\RelevantEvidence;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Loader\TextFileGranularity;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

/** @return array{dataset:EvaluationDataset,documents:array<string,string>,chunks:list<Chunk>,paths:list<string>} */
function algorithms_golden(string $tenant, string $collection): array
{
    $paths = array_map(
        static fn (string $filename): string => dirname(__DIR__).'/documents/'.$filename,
        ['algorithms-golden.md', 'estrutura_dados_php.txt', 'optimal-bst-python.txt'],
    );
    $splitter = new StructuralTextSplitter(new CharacterLimitStrategy(700));
    $documents = [];
    $chunks = [];

    foreach ($paths as $path) {
        $loader = algorithms_loader($path, $tenant, $collection);
        foreach ($loader->load() as $document) {
            $documents[basename($path)] = $document->id;
            foreach ($splitter->split($document) as $chunk) {
                $chunks[] = $chunk;
            }
        }
    }

    $document = static function (string $filename) use ($documents): string {
        return $documents[$filename] ?? throw new RuntimeException("Golden document '{$filename}' does not exist.");
    };
    $matcher = new GoldenChunkMatcher();
    $match = static fn (GoldenMatchMode $mode, string ...$terms): array => $matcher->ids($chunks, $mode, $terms);
    $positive = static function (
        string $id,
        string $question,
        string $filename,
        array $relevantChunks,
        array $termGroups,
        ?string $expectedAnswer = null,
    ) use ($document, $chunks): EvaluationCase {
        if ($relevantChunks === []) {
            throw new RuntimeException("Golden case '{$id}' has no matching relevant chunks.");
        }
        $document($filename);
        $relevantDocuments = array_values(array_unique(array_map(
            static fn (Chunk $chunk): string => $chunk->documentId,
            array_filter($chunks, static fn (Chunk $chunk): bool => in_array($chunk->id, $relevantChunks, true)),
        )));
        if ($relevantDocuments === []) {
            throw new RuntimeException("Golden case '{$id}' has no matching relevant documents.");
        }
        return new EvaluationCase(
            id: $id,
            question: $question,
            expectedAnswer: $expectedAnswer,
            relevantChunkIds: $relevantChunks,
            relevantDocumentIds: $relevantDocuments,
            expectedTermGroups: $termGroups,
        );
    };

    $cases = [
        $positive('Dijkstra', 'Como funciona o algoritmo de Dijkstra?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Dijkstra', 'fila de prioridade', 'pesos não negativos'), [['Dijkstra'], ['menor caminho', 'caminho mais curto'], ['fila de prioridade', 'priority queue', 'min-heap']]),
        $positive('Bellman-Ford', 'Qual a complexidade do Bellman-Ford?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Bellman-Ford', 'O(VE)', 'pesos negativos'), [['Bellman-Ford'], ['O(VE)', 'O(V * E)', 'O(|V||E|)'], ['peso negativo', 'pesos negativos', 'arestas negativas', 'arestas com valores abaixo de zero', 'pesos de valor negativo']]),
        $positive('Quicksort', 'Explique o algoritmo Quicksort.', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Quicksort', 'pivô', 'particiona'), [['Quicksort'], ['pivô'], ['particiona', 'particionamento']]),
        $positive('Programação dinâmica', 'Como a programação dinâmica é aplicada no algoritmo Optimal BST?', 'optimal-bst-python.txt', $match(GoldenMatchMode::ANY, 'Algoritmo OPTIMAL-BST', 'custo candidato'), [['programação dinâmica', 'subproblema'], ['Optimal-BST', 'árvore de busca binária ótima'], ['custo esperado']]),
        $positive('Floyd-Warshall', 'Para que serve Floyd-Warshall?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Floyd-Warshall', 'todos os pares', 'O(V³)'), [['Floyd-Warshall'], ['todos os pares'], ['O(V³)', 'O(V^3)']]),
        $positive('Merge Sort', 'O que é Merge Sort?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Merge Sort', 'intercalação', 'O(n log n)'), [['Merge Sort'], ['intercalação', 'merge'], ['O(n log n)']]),
        $positive('Heap Sort', 'Como funciona Heap Sort?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'Heap Sort', 'max-heap', 'O(n log n)'), [['Heap Sort'], ['max-heap', 'heap máximo'], ['O(n log n)']]),
        $positive('Árvore AVL', 'O que é uma árvore AVL?', 'algorithms-golden.md', $match(GoldenMatchMode::ALL, 'árvore AVL', 'fator de balanceamento', 'rotações'), [['AVL'], ['fator de balanceamento'], ['rotação', 'rotações']]),
        new EvaluationCase(
            id: 'Tabela root',
            question: 'Explique a tabela root do Optimal BST.',
            relevantDocumentIds: [$document('optimal-bst-python.txt')],
            expectedTermGroups: [['root'], ['raiz'], ['intervalo']],
            relevantEvidence: [new RelevantEvidence($document('optimal-bst-python.txt'), [['root'], ['raiz'], ['intervalo']])],
        ),
        new EvaluationCase(
            id: 'Tabela w',
            question: 'O que representa w[i][j] no algoritmo Optimal BST?',
            relevantDocumentIds: [$document('optimal-bst-python.txt')],
            expectedTermGroups: [
                ['w[i][j]', 'tabela w', 'matriz w'],
                ['soma das probabilidades', 'aumento de profundidade', 'custo adicional'],
            ],
            relevantEvidence: [new RelevantEvidence($document('optimal-bst-python.txt'), [['w[i][j]']])],
        ),
        new EvaluationCase('Wesley', 'Como funciona o algoritmo Wesley?', expectNoEvidence: true),
        new EvaluationCase('XYZ-WESLEY-999', 'Explique o algoritmo XYZ-WESLEY-999.', expectNoEvidence: true),
        new EvaluationCase('FooBarInexistente', 'Qual é a complexidade da classe FooBarInexistente?', expectNoEvidence: true),
    ];

    $dataset = new EvaluationDataset($cases, 'Algorithms');
    return compact('dataset', 'documents', 'chunks', 'paths');
}

function algorithms_loader(string $path, string $tenant, string $collection): TextFileLoader
{
    return new TextFileLoader(
        path: $path,
        tenantId: $tenant,
        collection: $collection,
        granularity: TextFileGranularity::WHOLE_FILE,
        metadata: ['filename' => basename($path), 'format' => pathinfo($path, PATHINFO_EXTENSION)],
    );
}
