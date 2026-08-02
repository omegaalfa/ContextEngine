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

EnvLoader::load(dirname(__DIR__) . '/.env');

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Em quanto tempo posso solicitar um reembolso?';
}

$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
$collection = EnvLoader::get('CONTEXT_ENGINE_COLLECTION') ?? 'default';
$maximumDistance = 0.45;

/** Converte nanossegundos do hrtime para milissegundos. */
$milliseconds = static fn (int $elapsed): float => $elapsed / 1_000_000;

try {
    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_HOST') ?? '127.0.0.1',
        database: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?? 'context_engine',
        port: EnvLoader::getInt('CONTEXT_ENGINE_PGVECTOR_PORT') ?? 54339,
        username: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?? 'context_engine',
        password: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?? 'context_engine',
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));

    $eventLoop = new FiberEventLoop();
    $provider = new OllamaEmbeddingProvider(
        model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL') ?? 'bge-m3',
        dimensions: EnvLoader::getInt('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS') ?? 1024,
        client: new AsyncHttpClient($eventLoop),
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
    );

    $totalStartedAt = hrtime(true);

    $embeddingStartedAt = hrtime(true);
    $embedding = $provider->embed($question, $tenantId);
    $embeddingElapsed = hrtime(true) - $embeddingStartedAt;

    $searchStartedAt = hrtime(true);
    $results = $store->search(new VectorSearchQuery(
        tenantId: $tenantId,
        embedding: $embedding,
        policy: new RetrievalPolicy(
            limit: 5,
            metric: VectorMetric::COSINE,
            maximumDistance: $maximumDistance,
        ),
        collection: $collection,
        status: 'active',
    ));
    $searchElapsed = hrtime(true) - $searchStartedAt;
    $totalElapsed = hrtime(true) - $totalStartedAt;

    echo "\n╭────────────────── ContextEngine · RAG Debug ──────────────────╮\n";
    echo "│ Pergunta: {$question}\n";
    echo "│ Tenant: {$tenantId}\n";
    echo "│ Collection: {$collection}\n";
    echo "│ Espaço: {$embedding->space->provider}/{$embedding->space->model}\n";
    echo "│ Dimensões: {$embedding->space->dimensions}\n";
    echo "│ Fingerprint: {$embedding->space->fingerprint()}\n";
    echo "│ Filtro: distância cosseno ≤ " . number_format($maximumDistance, 2) . "\n";
    echo "╰───────────────────────────────────────────────────────────────╯\n\n";

    echo "Tempos\n";
    printf("  Embedding........ %8.2f ms\n", $milliseconds($embeddingElapsed));
    printf("  Busca + ranking.. %8.2f ms\n", $milliseconds($searchElapsed));
    printf("  Total medido..... %8.2f ms\n\n", $milliseconds($totalElapsed));

    if ($results === []) {
        echo "Nenhum chunk foi encontrado dentro do limite configurado.\n";
        exit(0);
    }

    echo 'Resultados (' . count($results) . ")\n\n";

    foreach ($results as $index => $result) {
        // Para distância cosseno, 1 - distância é uma apresentação intuitiva
        // da similaridade. O valor não deve ser reutilizado para outras métricas.
        $similarity = max(-1.0, min(1.0, 1.0 - $result->distance));

        printf(
            "#%d  distância=%.4f  similaridade=%.2f%%\n",
            $index + 1,
            $result->distance,
            $similarity * 100,
        );
        echo "    documento: {$result->chunk->documentId}\n";
        echo "    chunk: {$result->chunk->id}\n";
        echo "    posição: {$result->chunk->position}\n";
        echo "    status: {$result->chunk->status}\n";
        echo "    texto: {$result->chunk->content}\n\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "RAG Debug falhou: {$error->getMessage()}\n");
    exit(1);
}
