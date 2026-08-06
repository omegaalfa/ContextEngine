<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;

$path = __DIR__ . '/documents/Algoritimos e estrutura de dados em PHP.pdf';
$maximumPages = isset($argv[1]) ? max(1, (int) $argv[1]) : 5;
$extractor = new PopplerPdfTextExtractor(maximumPages: $maximumPages);

structural_demo_heading('06 — Prévia da extração do livro em PDF');

echo "Arquivo: {$path}" . PHP_EOL;
echo "Páginas solicitadas: {$maximumPages}" . PHP_EOL . PHP_EOL;

$pages = 0;
$characters = 0;

foreach ($extractor->extract($path) as $page) {
    $pages++;
    $characters += mb_strlen($page->text);
    $preview = preg_replace('/\s+/u', ' ', trim($page->text)) ?? '';

    echo str_repeat('-', 72) . PHP_EOL;
    echo "Página {$page->number} | método={$page->method} | " . mb_strlen($page->text) . ' caracteres' . PHP_EOL;
    echo mb_strimwidth($preview, 0, 360, '…') . PHP_EOL;
}

echo PHP_EOL . "Páginas extraídas: {$pages}" . PHP_EOL;
echo "Caracteres extraídos: {$characters}" . PHP_EOL;
