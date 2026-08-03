<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

final readonly class RagExecution
{
    public function __construct(public Answer $answer, public RagDiagnostics $diagnostics) {}
}
