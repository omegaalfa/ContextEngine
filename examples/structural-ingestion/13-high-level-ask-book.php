<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\RequestOptions;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

$question = trim(implode(' ', array_slice($argv, 1)));
if ($question === '') {
    $question = 'Como funciona o algoritmo quicksort?';
}

$config = structural_demo_config();
$tenantId = structural_demo_tenant();
$collection = $config->collection;
$languageModel = EnvLoader::get('CONTEXT_ENGINE_OLLAMA_MODEL') ?? 'llama3.1:8b';

$engine = ContextEngine::create()
    ->tenant($tenantId)
    ->collection($collection)
    ->ollama(
        baseUrl: $config->ollama->baseUrl,
        embeddingModel: $config->ollama->model,
        languageModel: $languageModel,
        embeddingDimensions: $config->ollama->dimensions,
    )
    ->retrieval(
        heuristicQueryPlanning: true,
        retrievalLimit: 8,
        fusedLimit: 5,
        contextChunkLimit: 5,
        maximumDistance: 0.60,
    )
    ->withLanguageModelFactory(
        static fn (): LanguageModel => new OllamaLanguageModel(
            model: $languageModel,
            client: new AsyncHttpClient(
                new FiberEventLoop(),
                new RequestOptions(
                    readTimeout: 180.0,
                    totalTimeout: 240.0,
                ),
            ),
            baseUrl: $config->ollama->baseUrl,
        ),
    )
    ->build();

structural_demo_heading('13 — Pergunta ao livro com High-Level API + IA');

echo "Pergunta: {$question}" . PHP_EOL;
echo "Tenant: {$tenantId}" . PHP_EOL;
echo "Collection: {$collection}" . PHP_EOL;
echo "Modelo de linguagem: {$languageModel}" . PHP_EOL . PHP_EOL;

try {
    $startedAt = hrtime(true);
    $execution = $engine->askWithDiagnostics($question, $tenantId);
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

    echo str_repeat('=', 72) . PHP_EOL;
    echo 'RESPOSTA' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo $execution->answer->content . PHP_EOL . PHP_EOL;

    echo str_repeat('=', 72) . PHP_EOL;
    echo 'FONTES ENVIADAS AO MODELO' . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;

    foreach ($execution->answer->sources as $index => $source) {
        $chunk = $source->chunk;
        $preview = preg_replace('/\s+/u', ' ', trim($chunk->content)) ?? '';

        echo '#' . ($index + 1)
            . ' | páginas ' . ($chunk->metadata['page_start'] ?? '?')
            . '–' . ($chunk->metadata['page_end'] ?? '?')
            . ' | distância=' . number_format($source->distance, 6, '.', '')
            . PHP_EOL;
        echo 'Heading: ' . ($chunk->metadata['heading_parent'] ?? '-') . PHP_EOL;
        echo mb_strimwidth($preview, 0, 280, '…') . PHP_EOL . PHP_EOL;
    }

    echo 'Tempo total: ' . number_format($elapsed, 2, ',', '.') . ' s' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'A resposta contextual falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
