<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\Node;

final readonly class ChunkBuilder
{
    public function __construct(private ChunkingStrategy $strategy) {}

    /** @return list<Chunk> */
    public function build(Document $document, DocumentNode $tree): array
    {
        $blocks = [];
        $this->flatten($tree->children, [], $blocks);
        $chunks = [];
        $pending = '';
        $pendingBlocks = 0;
        $pendingMetadata = null;
        foreach ($blocks as $block) {
            $candidate = $pending === '' ? $block['content'] : $pending . "\n\n" . $block['content'];
            if ($pending !== '' && !$this->strategy->fits($candidate, $pendingBlocks + 1)) {
                $this->append($chunks, $document, $pending, $pendingMetadata);
                $pending = '';
                $pendingBlocks = 0;
            }
            if (!$this->strategy->fits($block['content'], 1)) {
                foreach ($this->strategy->split($block['content']) as $part) {
                    $this->append($chunks, $document, $part, $block['metadata']);
                }
                continue;
            }
            $pending = $pending === '' ? $block['content'] : $pending . "\n\n" . $block['content'];
            $pendingBlocks++;
            $pendingMetadata ??= $block['metadata'];
        }
        if ($pending !== '') {
            $this->append($chunks, $document, $pending, $pendingMetadata);
        }

        return $chunks;
    }

    /**
     * @param list<Node> $nodes
     * @param list<string> $hierarchy
     * @param list<array{content: string, metadata: ChunkMetadata}> $blocks
     */
    private function flatten(array $nodes, array $hierarchy, array &$blocks): void
    {
        foreach ($nodes as $node) {
            $path = $hierarchy;
            if ($node->type() === 'heading' || $node->type() === 'section') {
                $level = (int) ($node->metadata()['level'] ?? count($path) + 1);
                $path = array_slice($path, 0, max(0, $level - 1));
                $path[] = $node->content();
            }
            if ($node->content() !== '') {
                $blocks[] = [
                    'content' => $node->content(),
                    'metadata' => new ChunkMetadata($node->type(), $path, count($path), count($blocks), $node->metadata()),
                ];
            }
            $this->flatten($node->children(), $path, $blocks);
            $hierarchy = $path;
        }
    }

    /** @param list<Chunk> $chunks */
    private function append(array &$chunks, Document $document, string $content, ?ChunkMetadata $metadata): void
    {
        if (trim($content) === '') {
            return;
        }
        $position = count($chunks);
        $chunkMetadata = array_merge($document->metadata, ($metadata ?? new ChunkMetadata('unknown', [], 0, $position))->toArray());
        $chunks[] = new Chunk(
            hash('sha256', implode("\0", [$document->tenantId, $document->id, (string) $position, $content])),
            $document->id,
            $document->tenantId,
            $content,
            $position,
            $chunkMetadata,
            $document->collection,
            $document->status,
        );
    }
}
