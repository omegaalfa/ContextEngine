<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use JsonException;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\Node;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\SectionNode;

final readonly class JsonParser implements DocumentParser
{
    /** @throws JsonException */
    public function parse(Document $document): DocumentNode
    {
        $value = json_decode($document->content, true, flags: JSON_THROW_ON_ERROR);

        return new DocumentNode($this->nodes($value), $document->metadata);
    }

    /** @return list<Node> */
    private function nodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [new ParagraphNode($this->scalar($value))];
        }
        $nodes = [];
        foreach ($value as $key => $child) {
            $children = is_array($child) ? $this->nodes($child) : [new ParagraphNode($this->scalar($child), ['key' => (string) $key])];
            $nodes[] = new SectionNode((string) $key, $children, ['path_segment' => (string) $key]);
        }

        return $nodes;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
