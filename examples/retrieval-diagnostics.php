<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');
$text = trim(implode(' ', array_slice($argv, 1)));
$text = $text === '' ? 'Converta optimal_bst para PHP 8.4.' : $text;
$tenantId = EnvLoader::get('CONTEXT_ENGINE_TENANT_ID') ?? 'empresa-exemplo';

try {
    $context = Bootstrap::create(
        ContextEngineConfigFactory::fromEnvironment(),
        static fn (AsyncHttpClient $http): LanguageModel => new class implements LanguageModel {
            public function complete(array $messages): string
            {
                return 'O modelo não é chamado neste exemplo.';
            }
        },
    );
    $outcome = $context->retriever->retrieveWithDiagnostics(new Question($text, $tenantId));
    $report = $outcome->diagnostics;
    echo PHP_EOL . '╭──────── ContextEngine · Retrieval Diagnostics ────────╮' . PHP_EOL;
    echo '│ Pergunta: ' . $report->originalQuestion . PHP_EOL;
    echo '│ Consultas: ' . count($report->queries) . PHP_EOL;
    echo '│ Fontes finais: ' . count($report->selectedChunkIds) . PHP_EOL;
    echo '│ Caracteres: ' . $report->contextCharacters . PHP_EOL;
    echo '╰───────────────────────────────────────────────────────╯' . PHP_EOL;
    foreach ($report->queries as $index => $query) {
        echo PHP_EOL . 'Consulta #' . ($index + 1) . ': ' . $query . PHP_EOL;
        foreach ($report->resultsByQuery[$query] as $hit) {
            printf(
                '  rank=%d distância=%.4f posição=%d documento=%s chunk=%s' . PHP_EOL,
                $hit->rank,
                $hit->distance,
                $hit->position,
                $hit->documentId,
                $hit->chunkId,
            );
        }
    }
    echo PHP_EOL . 'Ranking fundido: ' . implode(', ', $report->fusedChunkIds) . PHP_EOL;
    echo 'Vizinhos: ' . ($report->neighborChunkIds === [] ? 'nenhum' : implode(', ', $report->neighborChunkIds)) . PHP_EOL;
    echo 'Fontes finais: ' . implode(', ', $report->selectedChunkIds) . PHP_EOL;
    echo 'Descartados: ' . ($report->discardedByBudgetChunkIds === [] ? 'nenhum' : implode(', ', $report->discardedByBudgetChunkIds)) . PHP_EOL;
    if ($report->contextSelection !== []) {
        echo PHP_EOL . 'Sele��o adaptativa' . PHP_EOL;
        foreach ($report->contextSelection as $decision) {
            printf(
                '  %s chunk=%s motivo=%s' . PHP_EOL,
                $decision->selected ? 'selecionado' : 'descartado',
                $decision->chunkId,
                $decision->reason->value,
            );
        }
    }
    echo PHP_EOL . 'Tempos' . PHP_EOL;
    foreach ($report->timingsMilliseconds as $stage => $milliseconds) {
        printf('  %-16s %8.2f ms' . PHP_EOL, $stage, $milliseconds);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Diagnóstico de retrieval falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
