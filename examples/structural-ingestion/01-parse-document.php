<?php

declare(strict_types=1);

require __DIR__ . '/_support.php';

use Omegaalfa\ContextEngine\Ingestion\DocumentModel\Node;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;

$document = structural_demo_document();
$parser = new ParserRegistry()->parserFor($document);
$tree = $parser->parse($document);

structural_demo_heading('01 — Parsing: Markdown para árvore lógica');

echo 'Arquivo: ' . $document->metadata['source'] . PHP_EOL;
echo 'Parser: ' . $parser::class . PHP_EOL;
echo 'Nós raiz: ' . count($tree->children) . PHP_EOL . PHP_EOL;

$printNode = static function (Node $node, int $depth = 0) use (&$printNode): void {
    $indent = str_repeat('  ', $depth);
    $preview = preg_replace('/\s+/u', ' ', trim($node->content())) ?? '';
    $preview = mb_strimwidth($preview, 0, 72, '…');
    $metadata = $node->metadata() === []
        ? ''
        : ' ' . json_encode($node->metadata(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo sprintf('%s├─ %-10s %s%s', $indent, $node->type(), $preview, $metadata) . PHP_EOL;

    foreach ($node->children() as $child) {
        $printNode($child, $depth + 1);
    }
};

foreach ($tree->children as $node) {
    $printNode($node);
}
