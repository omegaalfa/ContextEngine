<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;


// O arquivo apenas completa valores ausentes; processo, Docker e CI têm precedência.
EnvLoader::load(dirname(__DIR__) . '/.env');

$questionText = trim(implode(' ', array_slice($argv, 1)));
if ($questionText === '') {
    $questionText = 'Converta para PHP 8.4 a função Python optimal_bst presente no contexto.';
}

$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';

try {
    $config = ContextEngineConfigFactory::fromEnvironment();

    // O Bootstrap compõe diretamente e entrega somente serviços públicos tipados.
    // A factory recebe o mesmo cliente HTTP usado pelo provider Ollama.
    $context = Bootstrap::create(
        config: $config,
        languageModelFactory: static fn (
            AsyncHttpClient $http,
        ): GeminiLanguageModel => new GeminiLanguageModel(
            apiKey: EnvLoader::get('CONTEXT_ENGINE_GEMINI_API_KEY'),
            model: EnvLoader::get('CONTEXT_ENGINE_GEMINI_MODEL'),
            client: $http
                ->readTimeout((float) (EnvLoader::getInt('CONTEXT_ENGINE_GEMINI_LLM_TIMEOUT') ?? 180))
                ->totalTimeout((float) (EnvLoader::getInt('CONTEXT_ENGINE_GEMINI_LLM_TIMEOUT') ?? 180)),
            baseUrl: EnvLoader::get('CONTEXT_ENGINE_GEMINI_URL'),
        ),
    );

    $answer = $context->rag->ask(new Question($questionText, $tenantId));
    echo "Model: " . EnvLoader::get('CONTEXT_ENGINE_OLLAMA_MODEL') . "\n";

    echo "\nPergunta\n{$questionText}\n\n";
    echo "Resposta\n{$answer->content}\n\n";
    echo 'Fontes utilizadas: ' . count($answer->sources) . "\n";

    foreach ($answer->sources as $index => $source) {
        printf(
            "  #%d distância=%.4f documento=%s chunk=%s\n",
            $index + 1,
            $source->distance,
            $source->chunk->documentId,
            $source->chunk->id,
        );
    }
} catch (Throwable $error) {
    fwrite(STDERR, "O exemplo RAG direto falhou: {$error->getMessage()}\n");
    exit(1);
}
