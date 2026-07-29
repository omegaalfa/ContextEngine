<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Document\Document;

interface DocumentLoader
{ /** @return iterable<Document> */ public function load(): iterable;
}
