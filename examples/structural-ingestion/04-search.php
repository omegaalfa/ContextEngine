<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Rag\Question;

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Como tratar o erro ERR_PAYMENT_1047 sem duplicar a captura?';
}

structural_demo_heading('04 — Busca vetorial nos chunks estruturais');

echo "Pergunta: {$question}" . PHP_EOL;
echo 'Tenant: ' . structural_demo_tenant() . PHP_EOL;
echo 'Collection: ' . structural_demo_config()->collection . PHP_EOL . PHP_EOL;

try {
    $results = structural_demo_context()->retriever->retrieve(
        new Question($question, structural_demo_tenant()),
    );

    if ($results === []) {
        echo 'Nenhum resultado encontrado. Execute 03-ingest.php primeiro.' . PHP_EOL;
        exit(0);
    }

    foreach ($results as $index => $result) {
        $chunk = $result->chunk;
        echo str_repeat('-', 72) . PHP_EOL;
        echo '#' . ($index + 1) . ' | distância=' . number_format($result->distance, 6, '.', '') . PHP_EOL;
        echo "Documento: {$chunk->documentId}" . PHP_EOL;
        echo "Chunk: {$chunk->position} ({$chunk->id})" . PHP_EOL;
        echo 'Heading: ' . ($chunk->metadata['heading_parent'] ?? '-') . PHP_EOL;
        echo 'Caminho: ' . ($chunk->metadata['hierarchy_path'] ?? '-') . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo $chunk->content . PHP_EOL . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'A busca falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
