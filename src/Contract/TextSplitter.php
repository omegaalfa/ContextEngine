<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Document\Document;

interface TextSplitter
{ /** @return iterable<Chunk> */ public function split(Document $document): iterable;
}
