<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\CodeBlockNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;

final readonly class PhpParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $tokens = token_get_all($document->content);
        $nodes = [];
        $start = 0;
        $offset = 0;
        $types = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum', T_FUNCTION => 'function'];
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && isset($types[$token[0]])) {
                if ($offset > $start && trim(substr($document->content, $start, $offset - $start)) !== '') {
                    $nodes[] = new CodeBlockNode(trim(substr($document->content, $start, $offset - $start)), ['language' => 'php', 'symbol_type' => 'preamble']);
                }
                $start = $offset;
                $nodes[] = new CodeBlockNode($this->symbolSignature($tokens, $offset, $document->content), ['language' => 'php', 'symbol_type' => $types[$token[0]]]);
            }
            $offset += strlen($text);
        }
        $tail = trim(substr($document->content, $start));
        if ($tail !== '') {
            $nodes[] = new CodeBlockNode($tail, ['language' => 'php']);
        }

        return new DocumentNode($nodes, array_merge($document->metadata, ['language' => 'php']));
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private function symbolSignature(array $tokens, int $offset, string $content): string
    {
        $end = strpos($content, '{', $offset);
        $semicolon = strpos($content, ';', $offset);
        if ($end === false || ($semicolon !== false && $semicolon < $end)) {
            $end = $semicolon;
        }

        return trim(substr($content, $offset, $end === false ? null : $end - $offset));
    }
}
