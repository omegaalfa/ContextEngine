<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\CodeBlockNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\HeadingNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ListNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\QuoteNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\TableNode;

final readonly class HtmlParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $nodes = [];
        preg_match_all('/<(h[1-6]|p|pre|code|blockquote|ul|ol|table)\b[^>]*>(.*?)<\/\1>/isu', $document->content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $tag = strtolower($match[1]);
            $content = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/iu', "\n", $match[2]) ?? $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($content === '') {
                continue;
            }
            $nodes[] = match (true) {
                str_starts_with($tag, 'h') => new HeadingNode($content, ['level' => (int) substr($tag, 1)]),
                $tag === 'pre', $tag === 'code' => new CodeBlockNode($content),
                $tag === 'blockquote' => new QuoteNode($content),
                $tag === 'ul', $tag === 'ol' => new ListNode($content, ['ordered' => $tag === 'ol']),
                $tag === 'table' => new TableNode($content),
                default => new ParagraphNode($content),
            };
        }
        if ($nodes === []) {
            $content = trim(html_entity_decode(strip_tags($document->content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $nodes[] = new ParagraphNode($content);
        }

        return new DocumentNode($nodes, $document->metadata);
    }
}
