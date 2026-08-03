<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Bootstrap;

use Closure;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Rag\FixedNoEvidencePolicy;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

/** Default direct composition for Ollama embeddings and PostgreSQL/pgvector. */
final class Bootstrap
{
    private function __construct() {}

    /** @param Closure(AsyncHttpClient): LanguageModel $languageModelFactory */
    public static function create(
        ContextEngineConfig $config,
        Closure $languageModelFactory,
    ): ContextEngineContext {
        $loop = new FiberEventLoop();
        $http = new AsyncHttpClient($loop);

        $connection = new PDOConnection(new DatabaseSettings(
            driver: 'pgsql',
            host: $config->database->host,
            database: $config->database->database,
            port: $config->database->port,
            username: $config->database->username,
            password: $config->database->password,
        ));
        $store = new PgVectorStore(new QueryBuilder($connection));

        $embeddings = new OllamaEmbeddingProvider(
            model: $config->ollama->model,
            dimensions: $config->ollama->dimensions,
            client: $http,
            baseUrl: $config->ollama->baseUrl,
        );
        $executor = new FiberBatchEmbeddingExecutor(
            loop: $loop,
            concurrency: $config->concurrency,
        );
        $policy = new RetrievalPolicy(
            limit: $config->retrievalLimit,
            metric: $config->retrievalMetric,
            maximumDistance: $config->maximumDistance,
        );
        $retriever = new Retriever(
            embeddings: $embeddings,
            store: $store,
            policy: $policy,
            collection: $config->collection,
            status: $config->status,
            queryRewriter: $config->heuristicQueryPlanning
                ? new HeuristicQueryRewriter()
                : new IdentityQueryRewriter(),
            neighborExpansion: new NeighborExpansion($config->neighborBefore, $config->neighborAfter),
            fusedLimit: $config->fusedLimit,
            contextChunkLimit: $config->contextChunkLimit,
            maximumContextCharacters: $config->maximumContextCharacters,
            contextRelevancePolicy: $config->adaptiveContextSelection
                ? new ContextRelevancePolicy(
                    maximumDistanceGap: $config->contextMaximumDistanceGap,
                    minimumSources: $config->contextMinimumSources,
                    maximumSources: $config->contextMaximumSources,
                    preferSameDocument: $config->contextPreferSameDocument,
                )
                : null,
        );
        $ingestion = new IngestionPipeline(
            splitter: new RecursiveTextSplitter(
                chunkSize: $config->chunkSize,
                overlap: $config->overlap,
            ),
            embeddings: $embeddings,
            store: $store,
            executor: $executor,
            batchSize: $config->batchSize,
        );

        $languageModel = $languageModelFactory($http);
        $rag = new RagPipeline(
            retriever: $retriever,
            prompts: new ContextPromptBuilder(),
            model: $languageModel,
            noEvidencePolicy: new FixedNoEvidencePolicy($config->noEvidenceMessage),
        );

        return new ContextEngineContext(
            retriever: $retriever,
            ingestion: $ingestion,
            rag: $rag,
            embeddings: $embeddings,
            store: $store,
        );
    }
}
