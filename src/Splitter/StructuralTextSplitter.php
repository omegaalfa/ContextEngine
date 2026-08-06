<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Splitter;

use Omegaalfa\ContextEngine\Contract\TextSplitter;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Chunking\ChunkBuilder;
use Omegaalfa\ContextEngine\Ingestion\Chunking\ChunkingStrategy;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;

final readonly class StructuralTextSplitter implements TextSplitter
{
    private ChunkBuilder $builder;

    public function __construct(
        private ChunkingStrategy $strategy = new CharacterLimitStrategy(),
        private ParserRegistry $parsers = new ParserRegistry(),
    ) {
        $this->builder = new ChunkBuilder($this->strategy);
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            'structural-text-splitter',
            '2',
            $this->strategy->fingerprint(),
            $this->parsers->fingerprint(),
        ]));
    }

    public function split(Document $document): iterable
    {
        $tree = $this->parsers->parserFor($document)->parse($document);
        yield from $this->builder->build($document, $tree);
    }
}
