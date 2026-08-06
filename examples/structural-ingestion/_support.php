<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfig;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineContext;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Loader\TextFileGranularity;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

EnvLoader::load(dirname(__DIR__, 2) . '/.env');

function structural_demo_path(): string
{
    return __DIR__ . '/documents/manual-pagamentos.md';
}

function structural_demo_tenant(): string
{
    return EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';
}

function structural_demo_config(): ContextEngineConfig
{
    return ContextEngineConfigFactory::fromEnvironment();
}

function structural_demo_document(): Document
{
    $path = structural_demo_path();
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("Não foi possível ler {$path}.");
    }

    return new Document(
        id: hash('sha256', structural_demo_tenant() . "\0" . realpath($path)),
        tenantId: structural_demo_tenant(),
        content: $content,
        metadata: [
            'format' => 'markdown',
            'source' => realpath($path) ?: $path,
            'title' => 'Manual de pagamentos',
            'version' => '1',
            'language' => 'pt-BR',
        ],
        collection: structural_demo_config()->collection,
    );
}

function structural_demo_loader(): TextFileLoader
{
    $config = structural_demo_config();

    return new TextFileLoader(
        path: structural_demo_path(),
        tenantId: structural_demo_tenant(),
        collection: $config->collection,
        status: $config->status,
        granularity: TextFileGranularity::WHOLE_FILE,
        metadata: [
            'format' => 'markdown',
            'title' => 'Manual de pagamentos',
            'version' => '1',
            'language' => 'pt-BR',
        ],
    );
}

function structural_demo_context(): ContextEngineContext
{
    return Bootstrap::create(
        config: structural_demo_config(),
        languageModelFactory: static fn (): LanguageModel => new StructuralDemoLanguageModel(),
    );
}

function structural_demo_heading(string $title): void
{
    echo PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    echo $title . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
}

final readonly class StructuralDemoLanguageModel implements LanguageModel
{
    public function complete(array $messages): string
    {
        return 'Este modelo não é usado nos exemplos de parsing, ingestão ou busca.';
    }
}
