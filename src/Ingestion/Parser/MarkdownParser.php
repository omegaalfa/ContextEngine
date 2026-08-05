<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\CodeBlockNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\HeadingNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ListNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\Node;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\QuoteNode;

final readonly class MarkdownParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $nodes = [];
        $lines = preg_split('/\R/u', $document->content) ?: [];
        $buffer = [];
        $mode = null;
        $language = null;
        foreach ($lines as $line) {
            if (preg_match('/^```\s*([\w+-]*)/', $line, $match) === 1) {
                if ($mode === 'code') {
                    $nodes[] = new CodeBlockNode(implode("\n", $buffer), ['language' => $language]);
                    $buffer = [];
                    $mode = null;
                } else {
                    $this->flush($nodes, $buffer, $mode);
                    $mode = 'code';
                    $language = $match[1] !== '' ? $match[1] : null;
                }
                continue;
            }
            if ($mode === 'code') {
                $buffer[] = $line;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $match) === 1) {
                $this->flush($nodes, $buffer, $mode);
                $nodes[] = new HeadingNode(trim($match[2]), ['level' => strlen($match[1])]);
                continue;
            }
            $lineMode = preg_match('/^\s*[-*+]\s+/u', $line) === 1 ? 'list' : (preg_match('/^\s*>\s?/u', $line) === 1 ? 'quote' : 'paragraph');
            if (trim($line) === '') {
                $this->flush($nodes, $buffer, $mode);
                continue;
            }
            if ($mode !== null && $mode !== $lineMode) {
                $this->flush($nodes, $buffer, $mode);
            }
            $mode = $lineMode;
            $buffer[] = $line;
        }
        $this->flush($nodes, $buffer, $mode);

        return new DocumentNode($nodes, $document->metadata);
    }

    /**
     * @param list<Node> $nodes
     * @param list<string> $buffer
     * @param-out null $mode
     */
    private function flush(array &$nodes, array &$buffer, ?string &$mode): void
    {
        if ($buffer === []) {
            $mode = null;
            return;
        }
        $content = trim(implode("\n", $buffer));
        $nodes[] = match ($mode) {
            'list' => new ListNode($content),
            'quote' => new QuoteNode($content),
            'code' => new CodeBlockNode($content),
            default => new ParagraphNode($content),
        };
        $buffer = [];
        $mode = null;
    }
}
