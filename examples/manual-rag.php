<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\Question;
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

$questionText = trim(implode(' ', array_slice($argv, 1)));
if ($questionText === '') {
    $questionText = 'Em quanto tempo posso solicitar um reembolso?';
}

$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
$collection = EnvLoader::get('CONTEXT_ENGINE_COLLECTION') ?? 'default';

try {
    // 1. Prepara o VectorStore com os chunks que já foram ingeridos.
    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_HOST') ?? '127.0.0.1',
        database: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_DATABASE') ?? 'context_engine',
        port: EnvLoader::getInt('CONTEXT_ENGINE_PGVECTOR_PORT') ?? 54339,
        username: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_USERNAME') ?? 'context_engine',
        password: EnvLoader::get('CONTEXT_ENGINE_PGVECTOR_PASSWORD') ?? 'context_engine',
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));

    // 2. Compartilha um event loop entre os clientes HTTP usados neste processo.
    $eventLoop = new FiberEventLoop();
    $http = new AsyncHttpClient($eventLoop);

    // 3. Converte a pergunta no mesmo espaço vetorial usado durante a ingestão.
    $embeddings = new OllamaEmbeddingProvider(
        model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL') ?? 'bge-m3',
        dimensions: EnvLoader::getInt('CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS') ?? 1024,
        client: $http,
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
    );
    $question = new Question($questionText, $tenantId);
    $questionEmbedding = $embeddings->embed($question->content, $question->tenantId);

    // 4. Recupera poucos chunks relevantes entre todos os vetores armazenados.
    $sources = $store->search(new VectorSearchQuery(
        tenantId: $question->tenantId,
        embedding: $questionEmbedding,
        policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE, maximumDistance: 0.45),
        collection: $collection,
        status: 'active',
    ));

    if ($sources === []) {
        echo "Nenhuma fonte confiável foi encontrada dentro do limite configurado.\n";
        exit(0);
    }

    // 5. Monta mensagens com a pergunta e somente os chunks recuperados.
    $promptBuilder = new ContextPromptBuilder(
        system: 'Responda em português usando somente o contexto fornecido. Se o contexto não contiver a resposta, diga que não possui informação suficiente.',
        version: '1',
    );
    $messages = $promptBuilder->build($question, $sources);

    // 6. Para Gemini, substitua este objeto por uma implementação de LanguageModel.
    $languageModel = new OpenAILanguageModel(
        apiKey: EnvLoader::require('CONTEXT_ENGINE_OPENAI_API_KEY'),
        model: EnvLoader::get('CONTEXT_ENGINE_OPENAI_MODEL') ?? 'gpt-4.1-mini',
        client: $http,
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OPENAI_URL') ?? 'https://api.openai.com/v1',
    );
    $answer = $languageModel->complete($messages);

    echo "\nPergunta\n{$question->content}\n\n";
    echo 'Fontes enviadas ao LLM: ' . count($sources) . "\n";
    foreach ($sources as $index => $source) {
        printf("  #%d distância=%.4f documento=%s chunk=%s\n", $index + 1, $source->distance, $source->chunk->documentId, $source->chunk->id);
    }
    echo "\nResposta do LLM\n{$answer}\n";
} catch (Throwable $error) {
    fwrite(STDERR, "O exemplo RAG manual falhou: {$error->getMessage()}\n");
    exit(1);
}
