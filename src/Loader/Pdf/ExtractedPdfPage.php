<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Loader\Pdf;

use InvalidArgumentException;

final readonly class ExtractedPdfPage
{
    public function __construct(
        public int $number,
        public string $text,
        public string $method = 'text',
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException('PDF page number must be greater than zero.');
        }
        if (trim($method) === '') {
            throw new InvalidArgumentException('PDF extraction method cannot be empty.');
        }
    }
}
