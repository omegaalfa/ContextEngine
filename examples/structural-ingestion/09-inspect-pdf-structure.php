<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$path = __DIR__ . '/documents/Algoritimos e estrutura de dados em PHP.pdf';
$maximumPages = isset($argv[1]) ? max(1, (int) $argv[1]) : 100;
$limit = isset($argv[2]) ? max(100, (int) $argv[2]) : 1_200;
$config = structural_demo_config();

$loader = new PdfDocumentLoader(
    path: $path,
    tenantId: structural_demo_tenant(),
    extractor: new PopplerPdfTextExtractor(maximumPages: $maximumPages),
    collection: $config->collection,
    pagesPerDocument: PHP_INT_MAX,
    metadata: ['content_kind' => 'book'],
);

$documents = iterator_to_array($loader->load());
$document = $documents[0] ?? throw new RuntimeException('O PDF não produziu documento textual.');
$parser = new ParserRegistry()->parserFor($document);
$tree = $parser->parse($document);
$chunks = iterator_to_array(new StructuralTextSplitter(new CharacterLimitStrategy($limit))->split($document));

$types = [];
foreach ($tree->children as $node) {
    $types[$node->type()] = ($types[$node->type()] ?? 0) + 1;
}

structural_demo_heading('09 — Estrutura lógica detectada no PDF');

echo "Páginas analisadas: {$maximumPages}" . PHP_EOL;
echo 'Documentos lógicos: ' . count($documents) . PHP_EOL;
echo 'Parser: ' . $parser::class . PHP_EOL;
echo 'Nós: ' . count($tree->children) . PHP_EOL;
echo 'Tipos: ' . json_encode($types, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "Limite de chunk: {$limit} caracteres" . PHP_EOL;
echo 'Chunks: ' . count($chunks) . PHP_EOL . PHP_EOL;

foreach (array_slice($chunks, 0, 12) as $chunk) {
    $preview = preg_replace('/\s+/u', ' ', trim($chunk->content)) ?? '';
    echo sprintf(
        '#%d | páginas %s–%s | %s | %d caracteres',
        $chunk->position,
        $chunk->metadata['page_start'] ?? '?',
        $chunk->metadata['page_end'] ?? '?',
        $chunk->metadata['heading_parent'] ?? '-',
        mb_strlen($chunk->content),
    ) . PHP_EOL;
    echo mb_strimwidth($preview, 0, 180, '…') . PHP_EOL . PHP_EOL;
}
