<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

// Variáveis do processo, Docker ou CI têm precedência sobre o arquivo .env.
EnvLoader::load(dirname(__DIR__) . '/.env');

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Qual a política de home office?';
}

$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
$collection = EnvLoader::get('CONTEXT_ENGINE_COLLECTION') ?? 'default';

try {
    // 1. Conecta ao banco que já contém os chunks ingeridos.
    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_HOST') ?? '127.0.0.1',
        database: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?? 'context_engine',
        port: EnvLoader::getInt('CONTEXT_ENGINE_PGVECTOR_PORT') ?? 54339,
        username: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?? 'context_engine',
        password: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?? 'context_engine',
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));

    // 2. Usa exatamente o mesmo espaço vetorial empregado durante a ingestão.
    $eventLoop = new FiberEventLoop();
    $provider = new OllamaEmbeddingProvider(
        model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL') ?? 'bge-m3',
        dimensions: EnvLoader::getInt('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS') ?? 1024,
        client: new AsyncHttpClient($eventLoop),
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
    );

    // 3. O provider já devolve um Embedding validado com valores e EmbeddingSpace.
    $questionEmbedding = $provider->embed($question, $tenantId);

    // 4. Consulta diretamente o VectorStore, sem usar o Retriever.
    $results = $store->search(new VectorSearchQuery(
        tenantId: $tenantId,
        embedding: $questionEmbedding,
        policy: new RetrievalPolicy(
            limit: 5,
            metric: VectorMetric::COSINE,
        ),
        collection: $collection,
        status: 'active',
    ));

    echo "Pergunta: {$question}\n";
    echo "Tenant: {$tenantId} | Collection: {$collection}\n";
    echo "Espaço: {$questionEmbedding->space->fingerprint()}\n\n";

    if ($results === []) {
        echo "Nenhum chunk ficou dentro da distância máxima configurada.\n";
        exit(0);
    }

    foreach ($results as $result) {
        printf(
            "chunk=%s documento=%s distância=%.4f\n%s\n\n",
            $result->chunk->id,
            $result->chunk->documentId,
            $result->distance,
            $result->chunk->content,
        );
    }
} catch (Throwable $error) {
    fwrite(STDERR, "A busca vetorial falhou: {$error->getMessage()}\n");
    exit(1);
}
