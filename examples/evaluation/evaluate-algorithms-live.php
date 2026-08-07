<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/_algorithms_golden.php';

use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\EvaluationStatus;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;
use Omegaalfa\ContextEngine\Evaluation\Support\TextComparison;

const TENANT = 'evaluation-algorithms-live';
const COLLECTION = 'evaluation-algorithms-live';

echo PHP_EOL.str_repeat('=', 66).PHP_EOL;
echo '  ContextEngine Live RAG Evaluation'.PHP_EOL;
echo str_repeat('=', 66).PHP_EOL;
echo 'Mode: live / external infrastructure'.PHP_EOL;
echo 'Requires: configured embeddings, vector store and language model.'.PHP_EOL.PHP_EOL;

try {
    $golden = algorithms_golden(TENANT, COLLECTION);
    $caseFilter = trim((string) ($argv[1] ?? ''));
    if ($caseFilter !== '') {
        $cases = array_values(array_filter(
            $golden['dataset']->cases,
            static fn ($case): bool => mb_strtolower($case->id) === mb_strtolower($caseFilter),
        ));
        if ($cases === []) {
            throw new InvalidArgumentException("Golden case '{$caseFilter}' was not found.");
        }
        $golden['dataset'] = new EvaluationDataset($cases, $golden['dataset']->name.' / '.$cases[0]->id);
    }
    $context = ContextEngine::create()
        ->tenant(TENANT)
        ->collection(COLLECTION)
        ->ingestion(chunkSize: 700, chunkOverlap: 0)
        ->retrieval(retrievalLimit: 5, fusedLimit: 5, contextChunkLimit: 5, hybridSearch: true)
        ->build();

    foreach ($golden['paths'] as $path) {
        $context->ingest(algorithms_loader($path, TENANT, COLLECTION));
    }

    $report = new RagEvaluator(TENANT)->evaluate($context->rag, $golden['dataset']);
    $averages = [];
    $denominators = [];
    foreach ($report->results as $result) {
        foreach ($result->scores as $name => $score) {
            $averages[$name] = ($averages[$name] ?? 0.0) + $score->value;
            $denominators[$name] = ($denominators[$name] ?? 0) + 1;
        }
    }

    echo 'Dataset: '.$report->datasetName.PHP_EOL;
    echo 'Cases: '.$report->executedCases.PHP_EOL;
    echo 'Positive cases: '.count(array_filter($report->results, static fn ($result): bool => !$result->case->expectNoEvidence)).PHP_EOL;
    echo 'Negative cases: '.count(array_filter($report->results, static fn ($result): bool => $result->case->expectNoEvidence)).PHP_EOL.PHP_EOL;
    foreach (['chunk_recall', 'document_recall', 'evidence_recall', 'strict_exact_match', 'normalized_exact_match', 'contains_expected_terms', 'no_evidence'] as $name) {
        $count = $denominators[$name] ?? 0;
        $value = $count === 0 ? 'n/a' : number_format($averages[$name] / $count, 2);
        echo str_pad($name.' ', 30, '.').' '.$value." ({$count} casos)".PHP_EOL;
    }
    echo PHP_EOL.str_repeat('-', 66).PHP_EOL;
    foreach ($report->results as $result) {
        $symbol = match ($result->status) {
            EvaluationStatus::PASSED => '✔',
            EvaluationStatus::FAILED => '✘',
            EvaluationStatus::ERROR => '!',
            EvaluationStatus::NOT_APPLICABLE => '–',
        };
        echo PHP_EOL.$symbol.' '.$result->case->id.' ['.strtoupper($result->status->value).']'.PHP_EOL;
        echo '  Tempo: '.number_format($result->durationMilliseconds, 2).' ms'.PHP_EOL;
        if ($result->execution !== null) {
            echo '  Chunks recuperados: '.count($result->execution->diagnostics->retrieval->fusedChunkIds).PHP_EOL;
            echo '  Chunks selecionados: '.count($result->execution->diagnostics->retrieval->selectedChunkIds).PHP_EOL;
            echo '  Modelo chamado: '.($result->execution->diagnostics->modelCalled ? 'sim' : 'não').PHP_EOL;
        }
        if ($result->execution !== null) {
            echo '  Consultas: '.implode(' | ', $result->execution->diagnostics->retrieval->queries).PHP_EOL;
            echo '  Resposta: '.$result->execution->answer->content.PHP_EOL;
            $answer = TextComparison::normalize($result->execution->answer->content);
            $groups = [...$result->case->expectedTermGroups, ...array_map(static fn (string $term): array => [$term], $result->case->expectedTerms)];
            foreach ($groups as $group) {
                $found = array_find($group, static fn (string $term): bool => str_contains($answer, TextComparison::normalize($term)));
                echo '  '.($found === null ? '[faltou]' : '[ok]').' Termo: '.($found ?? implode(' | ', $group)).PHP_EOL;
            }
            foreach ($result->execution->answer->sources as $source) {
                $matches = array_map(static fn ($match): string => $match->query.'#'.$match->rank, $source->matches);
                echo '  Fonte: '.$source->chunk->id.' doc='.$source->chunk->documentId.' distance='.number_format($source->distance, 4).' sinais='.($matches === [] ? 'vetorial' : implode(', ', $matches)).PHP_EOL;
            }
        }
        foreach ($result->scores as $score) {
            echo sprintf('  %-27s %.2f %s', $score->name, $score->value, $score->passed ? '✔' : '✘').PHP_EOL;
        }
        if ($result->error !== null) {
            echo '  Erro: '.$result->error.PHP_EOL;
        }
    }
    echo PHP_EOL.str_repeat('-', 66).PHP_EOL;
    echo "Resultado: {$report->passedCases}/{$report->executedCases} casos aprovados".PHP_EOL.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Live evaluation unavailable: '.$exception->getMessage().PHP_EOL);
    fwrite(STDERR, 'Configure .env and external services before running this mode.'.PHP_EOL);
    exit(1);
}
