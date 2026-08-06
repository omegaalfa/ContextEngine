<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Exception\IngestionException;

structural_demo_heading('03 — Ingestão estrutural no PostgreSQL/pgvector');

$config = structural_demo_config();

echo 'Tenant: ' . structural_demo_tenant() . PHP_EOL;
echo 'Collection: ' . $config->collection . PHP_EOL;
echo 'Documento: ' . structural_demo_path() . PHP_EOL;
echo 'Ollama: ' . $config->ollama->baseUrl . PHP_EOL . PHP_EOL;

try {
    $report = structural_demo_context()->ingestion->ingest(structural_demo_loader());

    echo 'Ingestão concluída.' . PHP_EOL;
    echo "Documentos ativados: {$report->documentsActivated}" . PHP_EOL;
    echo "Chunks produzidos: {$report->chunksProduced}" . PHP_EOL;
    echo "Chunks persistidos: {$report->chunksPersisted}" . PHP_EOL;
    echo "Lotes persistidos: {$report->batchesPersisted}" . PHP_EOL;
} catch (IngestionException $error) {
    fwrite(STDERR, 'Falha de ingestão: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Chunks persistidos antes da falha: ' . $error->partialReport->chunksPersisted . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Não foi possível executar o exemplo: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
