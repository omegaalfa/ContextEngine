<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use DOMDocument;
use DOMElement;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\Node;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\SectionNode;
use RuntimeException;

final readonly class XmlParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($document->content, LIBXML_NONET | LIBXML_NOCDATA) || !$dom->documentElement instanceof DOMElement) {
            throw new RuntimeException('Invalid XML document.');
        }

        return new DocumentNode([$this->element($dom->documentElement)], $document->metadata);
    }

    private function element(DOMElement $element): Node
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $this->element($child);
            }
        }
        if ($children === []) {
            $children[] = new ParagraphNode(trim($element->textContent));
        }

        return new SectionNode($element->tagName, $children);
    }
}
