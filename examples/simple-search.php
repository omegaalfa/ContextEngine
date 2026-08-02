<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

// O .env apenas completa valores ausentes; processo, Docker e CI têm precedência.
EnvLoader::load(dirname(__DIR__) . '/.env');

$questionText = trim(implode(' ', array_slice($argv, 1)));
if ($questionText === '') {
    $questionText = 'Em quanto tempo posso solicitar um reembolso?';
}

$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
$collection = EnvLoader::get('CONTEXT_ENGINE_COLLECTION') ?? 'default';

try {
    // 1. Usa o mesmo banco e o mesmo espaço vetorial do exemplo de ingestão.
    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_HOST') ?? '127.0.0.1',
        database: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?? 'context_engine',
        port: EnvLoader::getInt('CONTEXT_ENGINE_PGVECTOR_PORT') ?? 54339,
        username: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?? 'context_engine',
        password: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?? 'context_engine',
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));

    // 2. A pergunta precisa ser vetorizada com o mesmo provider/modelo/dimensão.
    $eventLoop = new FiberEventLoop();
    $embeddings = new OllamaEmbeddingProvider(
        model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL') ?? 'bge-m3',
        dimensions: EnvLoader::getInt('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS') ?? 1024,
        client: new AsyncHttpClient($eventLoop),
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
    );

    // 3. O Retriever gera o embedding da pergunta e consulta somente versões ativas.
    $retriever = new Retriever(
        embeddings: $embeddings,
        store: $store,
        policy: new RetrievalPolicy(
            limit: 5,
            metric: VectorMetric::COSINE,
        ),
        collection: $collection,
        status: 'active',
    );

    $results = $retriever->retrieve(new Question($questionText, $tenantId));

    echo "Pergunta: {$questionText}\n";
    echo "Tenant: {$tenantId} | Collection: {$collection}\n\n";

    if ($results === []) {
        echo "Nenhum contexto compatível foi encontrado. Execute simple-ingestion.php primeiro.\n";
        exit(0);
    }

    foreach ($results as $index => $result) {
        $number = $index + 1;
        echo "#{$number} distância=" . number_format($result->distance, 6, '.', '') . "\n";
        echo "documento={$result->chunk->documentId} chunk={$result->chunk->id}\n";
        echo $result->chunk->content . "\n\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "A busca falhou: {$error->getMessage()}\n");
    exit(1);
}
