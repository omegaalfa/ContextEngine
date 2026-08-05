<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Chunking;

final readonly class ChunkMetadata
{
    /**
     * @param list<string> $hierarchy
     * @param array<string, scalar|null> $source
     */
    public function __construct(
        public string $blockType,
        public array $hierarchy,
        public int $level,
        public int $relativePosition,
        public array $source = [],
        public ?string $parentId = null,
    ) {}

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return array_merge($this->source, [
            'block_type' => $this->blockType,
            'heading_parent' => $this->hierarchy[array_key_last($this->hierarchy)] ?? null,
            'hierarchy_path' => implode(' > ', $this->hierarchy),
            'hierarchy_level' => $this->level,
            'relative_position' => $this->relativePosition,
            'parent_id' => $this->parentId,
        ]);
    }
}
