<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Retrieval\AdaptiveContextSelector;
use Omegaalfa\ContextEngine\Retrieval\ContextRelevancePolicy;
use Omegaalfa\ContextEngine\Retrieval\ContextSelector;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;

$question = 'Como implementar Fibonacci recursivo em PHP?';
$candidates = [
    new VectorSearchResult(
        new Chunk('fibonacci', 'algoritmos', 'empresa-exemplo', 'Fibonacci recursivo em PHP chama a pr�pria fun��o at� atingir o caso base.', 10),
        .12,
    ),
    new VectorSearchResult(
        new Chunk('heap', 'algoritmos', 'empresa-exemplo', 'Um heap m�ximo mant�m o maior elemento na raiz.', 30),
        .19,
    ),
    new VectorSearchResult(
        new Chunk('python', 'outro-livro', 'empresa-exemplo', 'Exemplo de ordena��o em Python.', 4),
        .31,
    ),
];

$current = new ContextSelector(chunkLimit: 5)->select($candidates);
$adaptive = new AdaptiveContextSelector(new ContextRelevancePolicy(
    maximumDistanceGap: .08,
    minimumSources: 1,
    maximumSources: 5,
    preferSameDocument: true,
))->select($question, $candidates);

echo 'ContextEngine - compara��o de contexto' . PHP_EOL;
echo 'Pergunta: ' . $question . PHP_EOL;
echo PHP_EOL . 'Comportamento atual (pol�tica desativada)' . PHP_EOL;
foreach ($current['selected'] as $result) {
    printf('  [selecionado] %-12s dist�ncia=%.2f' . PHP_EOL, $result->chunk->id, $result->distance);
}

echo PHP_EOL . 'Sele��o adaptativa' . PHP_EOL;
foreach ($adaptive['decisions'] as $decision) {
    printf(
        '  [%-10s] %-12s motivo=%s' . PHP_EOL,
        $decision->selected ? 'selecionado' : 'descartado',
        $decision->chunkId,
        $decision->reason->value,
    );
}
