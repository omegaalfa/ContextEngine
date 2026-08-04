<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

use Closure;
use InvalidArgumentException;
use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\Config\DatabaseConfig;
use Omegaalfa\ContextEngine\Bootstrap\Config\OllamaConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
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

final class ContextEngine
{
    /** @var array<string, mixed> */
    private array $overrides = [];

    private function __construct(
        private ContextEngineConfig $config,
        private ?Closure $languageModelFactory = null,
    ) {}

    public static function create(): self
    {
        return new self(new ContextEngineConfig(
            database: new DatabaseConfig(
                host: '127.0.0.1',
                database: 'context_engine',
                port: 54339,
                username: 'context_engine',
                password: 'context_engine',
            ),
            ollama: new OllamaConfig(
                model: 'bge-m3',
                dimensions: 1024,
                baseUrl: 'http://127.0.0.1:11434',
            ),
        ));
    }

    public static function fromEnvironment(): self
    {
        return new self(ContextEngineConfigFactory::fromEnvironment());
    }

    public function tenant(string $tenant): self
    {
        $this->overrides['tenant'] = $tenant;

        return $this;
    }

    public function collection(string $collection): self
    {
        $this->overrides['collection'] = $collection;

        return $this;
    }

    public function status(string $status): self
    {
        $this->overrides['status'] = $status;

        return $this;
    }

    public function ollama(
        string $baseUrl,
        string $embeddingModel,
        string $languageModel,
        int $embeddingDimensions = 1024,
    ): self {
        $this->overrides['provider'] = new ProviderConfig(
            provider: 'ollama',
            baseUrl: $baseUrl,
            embeddingModel: $embeddingModel,
            languageModel: $languageModel,
            embeddingDimensions: $embeddingDimensions,
        );

        return $this;
    }

    public function openAi(
        string $apiKey,
        string $model,
        string $baseUrl = 'https://api.openai.com/v1',
    ): self {
        $this->overrides['provider'] = new ProviderConfig(
            provider: 'openai',
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
        );

        return $this;
    }

    public function ingestion(
        ?int $batchSize = null,
        ?int $concurrency = null,
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
    ): self {
        $this->overrides['ingestion'] = new IngestionConfig(
            batchSize: $batchSize,
            concurrency: $concurrency,
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
        );

        return $this;
    }

    public function retrieval(
        ?bool $heuristicQueryPlanning = null,
        ?int $retrievalLimit = null,
        ?int $fusedLimit = null,
        ?int $contextChunkLimit = null,
        ?float $maximumDistance = null,
    ): self {
        $this->overrides['retrieval'] = new RetrievalConfig(
            heuristicQueryPlanning: $heuristicQueryPlanning,
            retrievalLimit: $retrievalLimit,
            fusedLimit: $fusedLimit,
            contextChunkLimit: $contextChunkLimit,
            maximumDistance: $maximumDistance,
        );

        return $this;
    }

    public function redis(?string $host = null, ?int $port = null, ?string $password = null): self
    {
        $this->overrides['redis'] = new RedisConfig(host: $host, port: $port, password: $password);

        return $this;
    }

    public function build(): ContextEngineContext
    {
        $config = $this->resolveConfig();
        $languageModelFactory = $this->languageModelFactory ?? $this->defaultLanguageModelFactory($config);
        $providerConfig = $this->providerConfig();

        if ($providerConfig instanceof ProviderConfig && $providerConfig->provider === 'openai') {
            return $this->composeOpenAI($config, $providerConfig, $languageModelFactory);
        }

        return $this->composeOllama($config, $languageModelFactory);
    }

    private function defaultLanguageModelFactory(ContextEngineConfig $config): Closure
    {
        return static function (AsyncHttpClient $http) use ($config): LanguageModel {
            return new OllamaLanguageModel(
                model: $config->ollama->model,
                client: $http,
                baseUrl: $config->ollama->baseUrl,
            );
        };
    }

    private function composeOllama(ContextEngineConfig $config, Closure $languageModelFactory): ContextEngineContext
    {
        return Bootstrap::create($config, $languageModelFactory);
    }

    private function composeOpenAI(ContextEngineConfig $config, ProviderConfig $providerConfig, Closure $languageModelFactory): ContextEngineContext
    {
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
        $embeddings = new OpenAIEmbeddingProvider(
            apiKey: $providerConfig->apiKey ?? throw new InvalidArgumentException('OpenAI API key is required.'),
            model: $providerConfig->model ?? 'text-embedding-3-small',
            dimensions: $providerConfig->embeddingDimensions ?? 1536,
            client: $http,
            baseUrl: $providerConfig->baseUrl ?? 'https://api.openai.com/v1',
        );
        $executor = new FiberBatchEmbeddingExecutor(loop: $loop, concurrency: $config->concurrency);
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

    private function resolveConfig(): ContextEngineConfig
    {
        $base = $this->config;
        $providerConfig = $this->providerConfig();
        $ingestionConfig = $this->ingestionConfig();
        $retrievalConfig = $this->retrievalConfig();

        $collection = is_string($this->overrides['collection'] ?? null) ? $this->overrides['collection'] : $base->collection;
        $status = is_string($this->overrides['status'] ?? null) ? $this->overrides['status'] : $base->status;
        $database = $base->database;
        $ollama = $base->ollama;

        if ($providerConfig instanceof ProviderConfig && $providerConfig->provider === 'ollama') {
            $ollama = new OllamaConfig(
                model: $providerConfig->embeddingModel ?? $base->ollama->model,
                dimensions: $providerConfig->embeddingDimensions ?? $base->ollama->dimensions,
                baseUrl: $providerConfig->baseUrl ?? $base->ollama->baseUrl,
            );
        }

        $batchSize = $ingestionConfig !== null && $ingestionConfig->batchSize !== null ? $ingestionConfig->batchSize : $base->batchSize;
        $concurrency = $ingestionConfig !== null && $ingestionConfig->concurrency !== null ? $ingestionConfig->concurrency : $base->concurrency;
        $chunkSize = $ingestionConfig !== null && $ingestionConfig->chunkSize !== null ? $ingestionConfig->chunkSize : $base->chunkSize;
        $overlap = $ingestionConfig !== null && $ingestionConfig->chunkOverlap !== null ? $ingestionConfig->chunkOverlap : $base->overlap;

        $retrievalLimit = $retrievalConfig !== null && $retrievalConfig->retrievalLimit !== null ? $retrievalConfig->retrievalLimit : $base->retrievalLimit;
        $heuristicQueryPlanning = $retrievalConfig !== null && $retrievalConfig->heuristicQueryPlanning !== null ? $retrievalConfig->heuristicQueryPlanning : $base->heuristicQueryPlanning;
        $fusedLimit = $retrievalConfig !== null && $retrievalConfig->fusedLimit !== null ? $retrievalConfig->fusedLimit : $base->fusedLimit;
        $contextChunkLimit = $retrievalConfig !== null && $retrievalConfig->contextChunkLimit !== null ? $retrievalConfig->contextChunkLimit : $base->contextChunkLimit;
        $maximumDistance = $retrievalConfig !== null && $retrievalConfig->maximumDistance !== null ? $retrievalConfig->maximumDistance : $base->maximumDistance;

        return new ContextEngineConfig(
            database: $database,
            ollama: $ollama,
            collection: $collection,
            status: $status,
            batchSize: $batchSize,
            concurrency: $concurrency,
            chunkSize: $chunkSize,
            overlap: $overlap,
            retrievalLimit: $retrievalLimit,
            retrievalMetric: $base->retrievalMetric,
            maximumDistance: $maximumDistance,
            heuristicQueryPlanning: $heuristicQueryPlanning,
            neighborBefore: $base->neighborBefore,
            neighborAfter: $base->neighborAfter,
            fusedLimit: $fusedLimit,
            contextChunkLimit: $contextChunkLimit,
            maximumContextCharacters: $base->maximumContextCharacters,
            adaptiveContextSelection: $base->adaptiveContextSelection,
            contextMaximumDistanceGap: $base->contextMaximumDistanceGap,
            contextMinimumSources: $base->contextMinimumSources,
            contextMaximumSources: $base->contextMaximumSources,
            contextPreferSameDocument: $base->contextPreferSameDocument,
            noEvidenceMessage: $base->noEvidenceMessage,
        );
    }

    private function providerConfig(): ?ProviderConfig
    {
        return $this->overrides['provider'] instanceof ProviderConfig ? $this->overrides['provider'] : null;
    }

    private function ingestionConfig(): ?IngestionConfig
    {
        return $this->overrides['ingestion'] instanceof IngestionConfig ? $this->overrides['ingestion'] : null;
    }

    private function retrievalConfig(): ?RetrievalConfig
    {
        return $this->overrides['retrieval'] instanceof RetrievalConfig ? $this->overrides['retrieval'] : null;
    }
}
