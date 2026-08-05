<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;

interface DocumentParser
{
    public function parse(Document $document): DocumentNode;
}
