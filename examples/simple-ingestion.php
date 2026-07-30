<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

// 1. Conecta ao PostgreSQL/pgvector do ContextEngine.
$databaseSettings = new DatabaseSettings(
    driver: 'pgsql',
    host: '127.0.0.1',
    database: 'context_engine',
    port: 54339,
    username: 'context_engine',
    password: 'context_engine',
);

$connection = new PDOConnection($databaseSettings);
$queryBuilder = new QueryBuilder($connection);
$vectorStore = new PgVectorStore($queryBuilder);

// 2. Compartilha o mesmo event loop entre HttpClient e executor de lotes.
$eventLoop = new FiberEventLoop();
$httpClient = new AsyncHttpClient($eventLoop);

// 3. Configura o modelo que transforma textos em vetores.
// O Ollama precisa estar ativo e ter o modelo bge-m3 instalado.
$embeddingProvider = new OllamaEmbeddingProvider(
    model: 'bge-m3',
    dimensions: 1024,
    client: $httpClient,
    baseUrl: 'http://127.0.0.1:11434',
);

$batchExecutor = new FiberBatchEmbeddingExecutor(
    loop: $eventLoop,
    concurrency: 4,
);

// 4. Monta a pipeline usando os objetos acima.
$pipeline = new IngestionPipeline(
    splitter: new RecursiveTextSplitter(
        chunkSize: 1_000,
        overlap: 150,
    ),
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 32,
    executor: $batchExecutor,
);

// 5. Escolhe o arquivo e o tenant que será dono dos documentos.
$loader = new TextFileLoader(
    path: __DIR__ . '/documents/politica-reembolso.txt',
    tenantId: 'empresa-exemplo',
);

// 6. Executa a ingestão e mostra o relatório.
try {
    $report = $pipeline->ingest($loader);
    echo "Ingestão concluída!\n";
    echo "Chunks produzidos: {$report->chunksProduced}\n";
    echo "Chunks persistidos: {$report->chunksPersisted}\n";
    echo "Lotes persistidos: {$report->batchesPersisted}\n";
} catch (IngestionException $error) {
    fwrite(STDERR, "A ingestão falhou: {$error->getMessage()}\n");
    fwrite(
        STDERR,
        "Chunks salvos antes da falha: {$error->partialReport->chunksPersisted}\n",
    );
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Erro: {$error->getMessage()}\n");
    exit(1);
}
