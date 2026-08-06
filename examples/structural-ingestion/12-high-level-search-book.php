<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\ContextEngine;

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Como funciona o algoritmo quicksort?';
}

$tenantId = structural_demo_tenant();
$collection = structural_demo_config()->collection;

$engine = ContextEngine::create()
    ->tenant($tenantId)
    ->collection($collection)
    ->retrieval(
        heuristicQueryPlanning: true,
        retrievalLimit: 8,
        fusedLimit: 5,
        contextChunkLimit: 5,
        maximumDistance: 0.60,
    )
    ->build();

structural_demo_heading('12 — Busca no livro com High-Level API');

echo "Pergunta: {$question}" . PHP_EOL;
echo "Tenant: {$tenantId}" . PHP_EOL;
echo "Collection: {$collection}" . PHP_EOL . PHP_EOL;

try {
    $results = array_values(array_filter(
        $engine->search($question, $tenantId),
        static fn ($result): bool => ($result->chunk->metadata['content_kind'] ?? null) === 'book',
    ));

    if ($results === []) {
        echo 'Nenhum trecho do livro foi encontrado. Execute 11-high-level-ingest-pdf.php primeiro.' . PHP_EOL;
        exit(0);
    }

    foreach ($results as $index => $result) {
        $chunk = $result->chunk;
        echo str_repeat('-', 72) . PHP_EOL;
        echo '#' . ($index + 1) . ' | distância=' . number_format($result->distance, 6, '.', '') . PHP_EOL;
        echo 'Páginas: ' . ($chunk->metadata['page_start'] ?? '?') . '–' . ($chunk->metadata['page_end'] ?? '?') . PHP_EOL;
        echo 'Heading: ' . ($chunk->metadata['heading_parent'] ?? '-') . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo $chunk->content . PHP_EOL . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'A busca High-Level falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
