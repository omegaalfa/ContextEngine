<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

use Omegaalfa\ContextEngine\Loader\Pdf\ExtractedPdfPage;

interface PdfTextExtractor
{
    /** @return iterable<ExtractedPdfPage> */
    public function extract(string $path): iterable;
}
