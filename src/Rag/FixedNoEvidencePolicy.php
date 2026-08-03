<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\NoEvidencePolicy;

final readonly class FixedNoEvidencePolicy implements NoEvidencePolicy
{
    public function __construct(
        private string $message = 'There is not enough evidence in the retrieved context to answer this question.',
    ) {
        if (trim($message) === '') {
            throw new InvalidArgumentException('No-evidence response cannot be empty.');
        }
    }

    public function response(Question $question): string
    {
        return $this->message;
    }
}
