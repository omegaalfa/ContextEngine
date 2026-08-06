<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Ingestion\Chunking\BlockLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Chunking\ChunkingStrategy;
use Omegaalfa\ContextEngine\Ingestion\Chunking\TokenLimitStrategy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$document = structural_demo_document();
$strategies = [
    'Caracteres (500)' => new CharacterLimitStrategy(500),
    'Tokens estimados (128)' => new TokenLimitStrategy(128),
    'Blocos estruturais (3)' => new BlockLimitStrategy(3),
];

structural_demo_heading('05 — Comparação de estratégias');

/** @var ChunkingStrategy $strategy */
foreach ($strategies as $name => $strategy) {
    $splitter = new StructuralTextSplitter($strategy);
    $chunks = iterator_to_array($splitter->split($document));
    $sizes = array_map(static fn ($chunk): int => mb_strlen($chunk->content), $chunks);

    echo $name . PHP_EOL;
    echo '  fingerprint: ' . $strategy->fingerprint() . PHP_EOL;
    echo '  chunks: ' . count($chunks) . PHP_EOL;
    echo '  tamanhos: [' . implode(', ', $sizes) . ']' . PHP_EOL;
    echo '  headings: [' . implode(', ', array_map(
        static fn ($chunk): string => (string) ($chunk->metadata['heading_parent'] ?? '-'),
        $chunks,
    )) . ']' . PHP_EOL . PHP_EOL;
}
