<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;

require_once "vendor/autoload.php";

$pipeline = new IngestionPipeline(
    splitter: new RecursiveTextSplitter(chunkSize: 1_000, overlap: 150),
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 32,
);

