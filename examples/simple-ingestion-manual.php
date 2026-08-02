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
use Omegaalfa\Utils\EnvLoader\EnvLoader;

// O .env apenas completa valores ausentes; processo, Docker e CI têm precedência.
EnvLoader::load(dirname(__DIR__) . '/.env');

// 1. Conecta ao PostgreSQL/pgvector do ContextEngine.
$databaseSettings = new DatabaseSettings(
    driver: 'pgsql',
    host: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_HOST') ?? '127.0.0.1',
    database: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?? 'context_engine',
    port: EnvLoader::getInt('CONTEXT_ENGINE_PGVECTOR_PORT') ?? 54339,
    username: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?? 'context_engine',
    password: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?? 'context_engine',
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
    model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL') ?? 'bge-m3',
    dimensions: EnvLoader::getInt('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS') ?? 1024,
    client: $httpClient,
    baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
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
    tenantId: EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo',
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
