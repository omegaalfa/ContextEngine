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
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\TableNode;

final readonly class PdfParser implements DocumentParser
{
    /** @var string */
    private const PAGE_PATTERN = '/\[\[CONTEXT_ENGINE_PAGE:(\d+)]]\s*/u';

    public function parse(Document $document): DocumentNode
    {
        $parts = preg_split(self::PAGE_PATTERN, $document->content, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $nodes = [];

        for ($index = 1; $index < count($parts); $index += 2) {
            $page = (int) $parts[$index];
            $content = $parts[$index + 1] ?? '';
            $this->parsePage($content, $page, $nodes);
        }

        if ($nodes === []) {
            return new PlainTextParser()->parse($document);
        }

        return new DocumentNode($nodes, $document->metadata);
    }

    /** @param list<Node> $nodes */
    private function parsePage(string $content, int $page, array &$nodes): void
    {
        $blocks = preg_split('/\R\s*\R/u', trim($content)) ?: [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $metadata = ['page_start' => $page, 'page_end' => $page];
            $lines = preg_split('/\R/u', $block) ?: [];
            $firstLine = trim($lines[0] ?? '');
            if (count($lines) > 1 && $this->isAttachedHeading($firstLine)) {
                $nodes[] = new HeadingNode($firstLine, array_merge($metadata, ['level' => $this->headingLevel($firstLine)]));
                $block = trim(implode("\n", array_slice($lines, 1)));
                if ($block === '') {
                    continue;
                }
            }

            $nodes[] = match (true) {
                $this->isCode($block) => new CodeBlockNode($block, array_merge($metadata, ['language' => 'php'])),
                $this->isList($block) => new ListNode($block, $metadata),
                $this->isTable($block) => new TableNode($block, $metadata),
                $this->isHeading($block) => new HeadingNode($block, array_merge($metadata, ['level' => $this->headingLevel($block)])),
                default => new ParagraphNode($block, $metadata),
            };
        }
    }

    private function isHeading(string $block): bool
    {
        return !str_contains($block, "\n") && $this->isHeadingCandidate($block);
    }

    private function isHeadingCandidate(string $block): bool
    {
        if (mb_strlen($block) > 120 || preg_match('/[.!?;:]$/u', $block) === 1) {
            return false;
        }

        $words = preg_split('/\s+/u', $block) ?: [];

        return count($words) <= 14
            && preg_match('/^(?:cap[ií]tulo\s+\d+|pref[aá]cio|introdu[cç][aã]o|conclus[aã]o|ap[eê]ndice|[\p{Lu}\d])/iu', $block) === 1;
    }

    private function isAttachedHeading(string $line): bool
    {
        $words = preg_split('/\s+/u', $line) ?: [];

        return mb_strlen($line) <= 64
            && count($words) <= 10
            && $this->isHeadingCandidate($line);
    }

    private function headingLevel(string $block): int
    {
        return preg_match('/^(?:cap[ií]tulo\s+\d+|pref[aá]cio|ap[eê]ndice)/iu', $block) === 1 ? 1 : 2;
    }

    private function isList(string $block): bool
    {
        return preg_match('/^(?:[•▪◦*-]|\d+[.)])\s+/u', ltrim($block)) === 1;
    }

    private function isCode(string $block): bool
    {
        return preg_match('/(?:<\?php|\b(?:class|interface|trait|enum|function)\s+\w+|\$\w+\s*=|->\w+\()/u', $block) === 1;
    }

    private function isTable(string $block): bool
    {
        $lines = preg_split('/\R/u', $block) ?: [];
        if (count($lines) < 2) {
            return false;
        }

        $tabularLines = array_filter($lines, static fn (string $line): bool => preg_match('/\S\s{2,}\S/u', $line) === 1);

        return count($tabularLines) >= 2;
    }
}
