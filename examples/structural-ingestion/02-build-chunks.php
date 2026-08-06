<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 500;
$document = structural_demo_document();
$splitter = new StructuralTextSplitter(new CharacterLimitStrategy($limit));
$chunks = iterator_to_array($splitter->split($document));

structural_demo_heading('02 — Chunking estrutural');

echo "Limite: {$limit} caracteres" . PHP_EOL;
echo 'Fingerprint: ' . $splitter->fingerprint() . PHP_EOL;
echo 'Chunks gerados: ' . count($chunks) . PHP_EOL;

foreach ($chunks as $chunk) {
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
    echo "CHUNK {$chunk->position} | " . mb_strlen($chunk->content) . ' caracteres' . PHP_EOL;
    echo 'Tipo inicial: ' . ($chunk->metadata['block_type'] ?? '-') . PHP_EOL;
    echo 'Heading pai: ' . ($chunk->metadata['heading_parent'] ?? '-') . PHP_EOL;
    echo 'Caminho: ' . ($chunk->metadata['hierarchy_path'] ?? '-') . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    echo $chunk->content . PHP_EOL;
}
