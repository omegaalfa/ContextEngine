<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Omegaalfa\ContextEngine\ContextEngine;

$engine = ContextEngine::create()
    ->tenant('empresa')
    ->collection('algorithms')
    ->ollama(
        baseUrl: 'http://127.0.0.1:11434',
        embeddingModel: 'bge-m3',
        languageModel: 'llama3.1:8b',
    )
    ->ingestion(
        batchSize: 32,
        concurrency: 4,
        chunkSize: 10000,
        chunkOverlap: 0,
    )
    ->retrieval(
        heuristicQueryPlanning: true,
        retrievalLimit: 3,
        fusedLimit: 1,
        contextChunkLimit: 1,
        maximumDistance: 0.60,
    )
    ->build();

printf("High-level engine ready: %s\n", $engine::class);
