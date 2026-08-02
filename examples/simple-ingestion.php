<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

// O arquivo apenas completa valores ausentes; processo, Docker e CI têm precedência.
EnvLoader::load(dirname(__DIR__) . '/.env');

$path = $argv[1] ?? __DIR__ . '/documents/politica-reembolso.txt';
$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';

try {
    $config = ContextEngineConfigFactory::fromEnvironment();

    // O Bootstrap cria um único cliente HTTP/event loop para todo o contexto.
    // O modelo de linguagem faz parte do contexto completo, mas não é chamado na ingestão.
    $context = Bootstrap::create(
        config: $config,
        languageModelFactory: static fn (
            AsyncHttpClient $http,
        ): GeminiLanguageModel => new GeminiLanguageModel(
            apiKey: EnvLoader::get('CONTEXT_ENGINE_GEMINI_API_KEY'),
            model: EnvLoader::get('CONTEXT_ENGINE_GEMINI_MODEL'),
            client: $http,
            baseUrl: EnvLoader::get('CONTEXT_ENGINE_GEMINI_URL'),
        ),
    );

    $loader = new TextFileLoader(
        path: $path,
        tenantId: $tenantId,
    );

    $report = $context->ingestion->ingest($loader);

    echo "\nIngestão concluída\n";
    echo "Arquivo: {$path}\n";
    echo "Tenant: {$tenantId}\n\n";
    echo "Documentos ativados: {$report->documentsActivated}\n";
    echo "Chunks produzidos: {$report->chunksProduced}\n";
    echo "Chunks enviados: {$report->chunksSent}\n";
    echo "Chunks persistidos: {$report->chunksPersisted}\n";
    echo "Lotes planejados: {$report->batchesPlanned}\n";
    echo "Lotes persistidos: {$report->batchesPersisted}\n";
} catch (IngestionException $error) {
    fwrite(STDERR, "A ingestão falhou: {$error->getMessage()}\n");
    fwrite(
        STDERR,
        "Chunks persistidos antes da falha: {$error->partialReport->chunksPersisted}\n",
    );
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "O exemplo de ingestão falhou: {$error->getMessage()}\n");
    exit(1);
}
