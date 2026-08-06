<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;

$path = __DIR__ . '/documents/Algoritimos e estrutura de dados em PHP.pdf';
$maximumPages = isset($argv[1]) && strtolower($argv[1]) !== 'all'
    ? max(1, (int) $argv[1])
    : null;
$chunkSize = isset($argv[2]) ? max(100, (int) $argv[2]) : 1_200;
$tenantId = structural_demo_tenant();
$collection = structural_demo_config()->collection;

$engine = ContextEngine::create()
    ->tenant($tenantId)
    ->collection($collection)
    ->ingestion(
        batchSize: 32,
        concurrency: 4,
        chunkSize: $chunkSize,
        chunkOverlap: 0,
    )
    ->build();

$loader = new PdfDocumentLoader(
    path: $path,
    tenantId: $tenantId,
    extractor: new PopplerPdfTextExtractor(
        timeoutSeconds: 120,
        maximumPages: $maximumPages,
    ),
    collection: $collection,
    pagesPerDocument: PHP_INT_MAX,
    metadata: [
        'title' => 'Algoritmos e estrutura de dados em PHP',
        'version' => '1',
        'language' => 'pt-BR',
        'content_kind' => 'book',
        'api_level' => 'high-level',
    ],
);

structural_demo_heading('11 — Ingestão do PDF com High-Level API');

echo 'Páginas: ' . ($maximumPages ?? 'todas') . PHP_EOL;
echo "Chunk máximo: {$chunkSize} caracteres" . PHP_EOL;
echo "Tenant: {$tenantId}" . PHP_EOL;
echo "Collection: {$collection}" . PHP_EOL . PHP_EOL;

try {
    $startedAt = hrtime(true);
    $report = $engine->ingest($loader);
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

    echo 'Ingestão concluída pela High-Level API.' . PHP_EOL;
    echo "Documentos ativados: {$report->documentsActivated}" . PHP_EOL;
    echo "Chunks produzidos: {$report->chunksProduced}" . PHP_EOL;
    echo "Chunks persistidos: {$report->chunksPersisted}" . PHP_EOL;
    echo "Lotes persistidos: {$report->batchesPersisted}" . PHP_EOL;
    echo 'Tempo total: ' . number_format($elapsed, 2, ',', '.') . ' s' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'A ingestão High-Level falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
