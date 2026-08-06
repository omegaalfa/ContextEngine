<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;

$path = __DIR__ . '/documents/Algoritimos e estrutura de dados em PHP.pdf';
$maximumPages = isset($argv[1]) && strtolower($argv[1]) !== 'all'
    ? max(1, (int) $argv[1])
    : null;
$chunkSize = isset($argv[2]) ? max(100, (int) $argv[2]) : 1_200;
putenv("CONTEXT_ENGINE_CHUNK_SIZE={$chunkSize}");
$config = structural_demo_config();

$loader = new PdfDocumentLoader(
    path: $path,
    tenantId: structural_demo_tenant(),
    extractor: new PopplerPdfTextExtractor(
        timeoutSeconds: 120,
        maximumPages: $maximumPages,
    ),
    collection: $config->collection,
    status: $config->status,
    pagesPerDocument: PHP_INT_MAX,
    metadata: [
        'title' => 'Algoritmos e estrutura de dados em PHP',
        'version' => '1',
        'language' => 'pt-BR',
        'content_kind' => 'book',
    ],
);

structural_demo_heading('07 — Ingestão do livro em PDF');

echo "Arquivo: {$path}" . PHP_EOL;
echo 'Páginas: ' . ($maximumPages ?? 'todas') . PHP_EOL;
echo 'Fronteira documental: livro completo' . PHP_EOL;
echo 'Páginas: usadas somente como metadata dos chunks' . PHP_EOL;
echo "Limite estrutural: {$chunkSize} caracteres" . PHP_EOL;
echo 'Tenant: ' . structural_demo_tenant() . PHP_EOL;
echo "Collection: {$config->collection}" . PHP_EOL . PHP_EOL;

try {
    $startedAt = hrtime(true);
    $report = structural_demo_context()->ingestion->ingest($loader);
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

    echo 'Ingestão concluída.' . PHP_EOL;
    echo "Documentos ativados: {$report->documentsActivated}" . PHP_EOL;
    echo "Chunks produzidos: {$report->chunksProduced}" . PHP_EOL;
    echo "Chunks persistidos: {$report->chunksPersisted}" . PHP_EOL;
    echo "Lotes persistidos: {$report->batchesPersisted}" . PHP_EOL;
    echo 'Tempo total: ' . number_format($elapsed, 2, ',', '.') . ' s' . PHP_EOL;
} catch (IngestionException $error) {
    fwrite(STDERR, 'Falha de ingestão: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Documento atual: ' . $error->documentId . PHP_EOL);
    fwrite(STDERR, 'Chunks persistidos antes da falha: ' . $error->partialReport->chunksPersisted . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Não foi possível ingerir o PDF: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
