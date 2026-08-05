<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\DocumentModel;

interface Node
{
    public function type(): string;

    public function content(): string;

    /** @return list<Node> */
    public function children(): array;

    /** @return array<string, scalar|null> */
    public function metadata(): array;
}
