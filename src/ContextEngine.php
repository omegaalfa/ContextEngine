<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine;

use Closure;
use InvalidArgumentException;
use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\Config\OllamaConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\HighLevel\IngestionConfig as HighLevelIngestionConfig;
use Omegaalfa\ContextEngine\HighLevel\ProviderConfig;
use Omegaalfa\ContextEngine\HighLevel\RedisConfig;
use Omegaalfa\ContextEngine\HighLevel\RetrievalConfig;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Ingestion\IngestionReport;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\Answer;
use Omegaalfa\ContextEngine\Rag\FixedNoEvidencePolicy;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Rag\RagExecution;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\HeuristicQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\HybridEvidencePolicy;
use Omegaalfa\ContextEngine\Retrieval\IdentityQueryRewriter;
use Omegaalfa\ContextEngine\Retrieval\NeighborExpansion;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;
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
        private ?Closure            $languageModelFactory = null,
    ) {}

    public static function create(): self
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
        int    $embeddingDimensions = 1024,
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

    public function openAiLanguageModel(
        string $apiKey,
        string $model = 'gpt-4.1-mini',
        string $baseUrl = 'https://api.openai.com/v1',
    ): self {
        $this->overrides['openAiLanguageModel'] = new ProviderConfig(
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
        $this->overrides['ingestion'] = new HighLevelIngestionConfig(
            batchSize: $batchSize,
            concurrency: $concurrency,
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
        );

        return $this;
    }

    public function retrieval(
        ?bool  $heuristicQueryPlanning = null,
        ?int   $retrievalLimit = null,
        ?int   $fusedLimit = null,
        ?int   $contextChunkLimit = null,
        ?float $maximumDistance = null,
        ?bool  $hybridSearch = null,
        ?float $vectorWeight = null,
        ?float $lexicalWeight = null,
    ): self {
        $this->overrides['retrieval'] = new RetrievalConfig(
            heuristicQueryPlanning: $heuristicQueryPlanning,
            retrievalLimit: $retrievalLimit,
            fusedLimit: $fusedLimit,
            contextChunkLimit: $contextChunkLimit,
            maximumDistance: $maximumDistance,
            hybridSearch: $hybridSearch,
            vectorWeight: $vectorWeight,
            lexicalWeight: $lexicalWeight,
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

    public function ingest(DocumentLoader $loader): IngestionReport
    {
        return $this->context()->ingestion->ingest($loader);
    }

    /** @return list<VectorSearchResult> */
    public function search(Question|string $question, ?string $tenantId = null): array
    {
        return $this->context()->retriever->retrieve($this->question($question, $tenantId));
    }

    public function ask(Question|string $question, ?string $tenantId = null): Answer
    {
        return $this->context()->rag->ask($question, $tenantId);
    }

    /** @return iterable<\Omegaalfa\ContextEngine\Rag\AnswerDelta> */
    public function stream(Question|string $question, ?string $tenantId = null): iterable
    {
        return $this->context()->rag->stream($question, $tenantId);
    }

    /** @return list<VectorSearchResult> */
    public function searchWithDiagnostics(Question|string $question, ?string $tenantId = null): array
    {
        return $this->context()->retriever->retrieveWithDiagnostics($this->question($question, $tenantId))->results;
    }

    public function askWithDiagnostics(Question|string $question, ?string $tenantId = null): RagExecution
    {
        return $this->context()->rag->askWithDiagnostics($question, $tenantId);
    }

    public function withCustomComponents(Closure $factory): self
    {
        $this->overrides['customFactory'] = $factory;

        return $this;
    }

    public function withLanguageModelFactory(Closure $factory): self
    {
        $this->languageModelFactory = $factory;

        return $this;
    }

    private function context(): ContextEngineContext
    {
        if (!isset($this->contextInstance)) {
            $this->contextInstance = $this->build();
        }

        return $this->contextInstance;
    }

    private ?ContextEngineContext $contextInstance = null;

    private function defaultLanguageModelFactory(ContextEngineConfig $config): Closure
    {
        $providerConfig = $this->providerConfig();
        $openAiLanguageModel = $this->openAiLanguageModelConfig();

        if ($providerConfig?->provider === 'ollama') {
            $model = $providerConfig->languageModel ?? $config->ollama->languageModel;
            $baseUrl = $providerConfig->baseUrl ?? $config->ollama->baseUrl;

            return static fn (AsyncHttpClient $http): LanguageModel => new OllamaLanguageModel(
                model: $model,
                client: $http,
                baseUrl: $baseUrl,
            );
        }

        if ($providerConfig?->provider === 'openai' || $openAiLanguageModel instanceof ProviderConfig) {
            $apiKey = ($openAiLanguageModel instanceof ProviderConfig ? $openAiLanguageModel->apiKey : null)
                ?? $providerConfig->apiKey
                ?? throw new InvalidArgumentException('OpenAI API key is required for OpenAI language model composition.');
            $model = ($openAiLanguageModel instanceof ProviderConfig ? $openAiLanguageModel->model : null) ?? 'gpt-4.1-mini';
            $baseUrl = ($openAiLanguageModel instanceof ProviderConfig ? $openAiLanguageModel->baseUrl : null)
                ?? $providerConfig->baseUrl
                ?? 'https://api.openai.com/v1';

            return static fn (AsyncHttpClient $http): LanguageModel => new OpenAILanguageModel(
                apiKey: $apiKey,
                model: $model,
                client: $http,
                baseUrl: $baseUrl,
            );
        }

        return static function (AsyncHttpClient $http) use ($config): LanguageModel {
            return new OllamaLanguageModel(
                model: $config->ollama->languageModel,
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
            contextRelevancePolicy: $config->adaptiveContextSelection && !$config->hybridSearch
                ? new ContextRelevancePolicy(
                    maximumDistanceGap: $config->contextMaximumDistanceGap,
                    minimumSources: $config->contextMinimumSources,
                    maximumSources: $config->contextMaximumSources,
                    preferSameDocument: $config->contextPreferSameDocument,
                )
                : null,
            lexicalStore: self::resolveLexicalStore($store, $config->hybridSearch),
            rankingWeights: [
                'vector' => $config->vectorRankingWeight,
                'lexical' => $config->lexicalRankingWeight,
            ],
            evidencePolicy: $config->hybridSearch ? new HybridEvidencePolicy() : null,
        );
        $ingestion = new IngestionPipeline(
            splitter: new StructuralTextSplitter(new CharacterLimitStrategy($config->chunkSize)),
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
            streamingModel: $languageModel instanceof StreamingLanguageModel ? $languageModel : null,
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
        $hybridSearch = $retrievalConfig !== null && $retrievalConfig->hybridSearch !== null ? $retrievalConfig->hybridSearch : $base->hybridSearch;
        $vectorRankingWeight = $retrievalConfig !== null && $retrievalConfig->vectorWeight !== null ? $retrievalConfig->vectorWeight : $base->vectorRankingWeight;
        $lexicalRankingWeight = $retrievalConfig !== null && $retrievalConfig->lexicalWeight !== null ? $retrievalConfig->lexicalWeight : $base->lexicalRankingWeight;

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
            hybridSearch: $hybridSearch,
            vectorRankingWeight: $vectorRankingWeight,
            lexicalRankingWeight: $lexicalRankingWeight,
            noEvidenceMessage: $base->noEvidenceMessage,
        );
    }

    private function providerConfig(): ?ProviderConfig
    {
        $provider = $this->overrides['provider'] ?? null;

        return $provider instanceof ProviderConfig ? $provider : null;
    }

    private function ingestionConfig(): ?HighLevelIngestionConfig
    {
        $ingestion = $this->overrides['ingestion'] ?? null;

        return $ingestion instanceof HighLevelIngestionConfig ? $ingestion : null;
    }

    private function retrievalConfig(): ?RetrievalConfig
    {
        $retrieval = $this->overrides['retrieval'] ?? null;

        return $retrieval instanceof RetrievalConfig ? $retrieval : null;
    }

    private function openAiLanguageModelConfig(): ?ProviderConfig
    {
        $openAiLanguageModel = $this->overrides['openAiLanguageModel'] ?? null;

        return $openAiLanguageModel instanceof ProviderConfig
            ? $openAiLanguageModel
            : null;
    }

    private function question(Question|string $question, ?string $tenantId): Question
    {
        if ($question instanceof Question) {
            return $question;
        }
        if ($tenantId === null) {
            throw new InvalidArgumentException('tenantId is required when question is a string.');
        }
        return new Question($question, $tenantId);
    }

    private static function resolveLexicalStore(object $store, bool $hybridSearch): ?LexicalSearchStore
    {
        if (!$hybridSearch) {
            return null;
        }
        if (!$store instanceof LexicalSearchStore) {
            throw new InvalidArgumentException('Hybrid search requires a vector store that implements LexicalSearchStore.');
        }

        return $store;
    }
}
