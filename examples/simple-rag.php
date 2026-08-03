<?php

declare(strict_types=1);

$startedAt = hrtime(true);

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
    $questionText = 'Compare Bellman-Ford e Dijkstra quanto a pesos negativos, detecção de ciclos negativos e complexidade.';
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
        ): OllamaLanguageModel => new OllamaLanguageModel(
            model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_MODEL'),
            client: $http
                ->readTimeout(300)
                ->totalTimeout(300),
            baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL')
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
    printf(
        PHP_EOL . 'Execução: %.3f s | Pico de memória: %.2f MiB' . PHP_EOL,
        (hrtime(true) - $startedAt) / 1_000_000_000,
        memory_get_peak_usage(true) / 1_048_576,
    );
} catch (Throwable $error) {
    fwrite(STDERR, "O exemplo RAG direto falhou: {$error->getMessage()}\n");
    exit(1);
}
