<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$bookPath = dirname(__DIR__) . '/algoritmos-teoria-e-prc3a1tica-3ed-thomas-cormen.pdf';
$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
$collection = EnvLoader::get('CONTEXT_ENGINE_PDF_COLLECTION') ?? 'algorithms';

try {
    $context = Bootstrap::create(
        config: ContextEngineConfigFactory::fromEnvironment(),
        languageModelFactory: static fn (
            AsyncHttpClient $http,
        ): GeminiLanguageModel => new GeminiLanguageModel(
            apiKey: (string) EnvLoader::get('CONTEXT_ENGINE_GEMINI_API_KEY'),
            model: EnvLoader::get('CONTEXT_ENGINE_GEMINI_MODEL') ?? 'gemini-3.6-flash',
            client: $http,
            baseUrl: EnvLoader::get('CONTEXT_ENGINE_GEMINI_URL')
                ?? 'https://generativelanguage.googleapis.com/v1beta',
        ),
    );

    $extractor = new PopplerPdfTextExtractor(
        binary: EnvLoader::get('CONTEXT_ENGINE_PDFTOTEXT_BINARY') ?? 'pdftotext',
        timeoutSeconds: EnvLoader::getInt('CONTEXT_ENGINE_PDF_EXTRACTION_TIMEOUT') ?? 180,
        maximumOutputBytes: 100_000_000,
        maximumPages: 500,
    );

    $loader = new PdfDocumentLoader(
        path: $bookPath,
        tenantId: $tenantId,
        extractor: $extractor,
        collection: $collection,
        status: 'active',
        pagesPerDocument: 3,
        metadata: [
            'type' => 'book',
            'title' => 'Algoritmos e estrutura de dados em PHP',
            'language' => 'pt-BR',
            'subject' => 'algorithms-and-data-structures',
        ],
    );

    echo "Iniciando ingestão do livro...\n";
    echo "Arquivo: {$bookPath}\n";
    echo "Tenant: {$tenantId}\n";
    echo "Collection: {$collection}\n";
    echo "Janela: 3 páginas por Document\n\n";

    $startedAt = hrtime(true);
    $report = $context->ingestion->ingest($loader);
    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

    echo "Ingestão concluída!\n\n";
    echo "Documentos ativados: {$report->documentsActivated}\n";
    echo "Chunks produzidos: {$report->chunksProduced}\n";
    echo "Chunks enviados: {$report->chunksSent}\n";
    echo "Chunks persistidos: {$report->chunksPersisted}\n";
    echo "Lotes planejados: {$report->batchesPlanned}\n";
    echo "Lotes persistidos: {$report->batchesPersisted}\n";
    printf("Tempo total: %.2f segundos\n", $elapsedSeconds);
} catch (IngestionException $error) {
    fwrite(STDERR, "A ingestão falhou: {$error->getMessage()}\n");
    fwrite(STDERR, "Documento: {$error->documentId}\n");
    fwrite(STDERR, "Chunks persistidos: {$error->partialReport->chunksPersisted}\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível ingerir o livro: {$error->getMessage()}\n");
    exit(1);
}
