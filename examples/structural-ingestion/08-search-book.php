<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Rag\Question;

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Como funciona uma lista encadeada em PHP?';
}

structural_demo_heading('08 — Busca vetorial no livro');

echo "Pergunta: {$question}" . PHP_EOL;
echo 'Tenant: ' . structural_demo_tenant() . PHP_EOL;
echo 'Collection: ' . structural_demo_config()->collection . PHP_EOL . PHP_EOL;

try {
    $results = structural_demo_context()->retriever->retrieve(
        new Question($question, structural_demo_tenant()),
    );

    $bookResults = array_values(array_filter(
        $results,
        static fn ($result): bool => ($result->chunk->metadata['content_kind'] ?? null) === 'book',
    ));

    if ($bookResults === []) {
        echo 'Nenhum trecho do livro foi encontrado. Execute 07-ingest-pdf.php primeiro.' . PHP_EOL;
        exit(0);
    }

    foreach ($bookResults as $index => $result) {
        $chunk = $result->chunk;
        echo str_repeat('-', 72) . PHP_EOL;
        echo '#' . ($index + 1) . ' | distância=' . number_format($result->distance, 6, '.', '') . PHP_EOL;
        echo "Documento: {$chunk->documentId}" . PHP_EOL;
        echo "Chunk: {$chunk->position}" . PHP_EOL;
        echo 'Páginas: ' . ($chunk->metadata['page_start'] ?? '?') . '–' . ($chunk->metadata['page_end'] ?? '?') . PHP_EOL;
        echo 'Origem: ' . ($chunk->metadata['source'] ?? '-') . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo $chunk->content . PHP_EOL . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'A busca no livro falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
