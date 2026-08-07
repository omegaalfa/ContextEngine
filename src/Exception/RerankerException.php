<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Exception;

use Throwable;

/** Falha operacional recuperável de um reranker remoto. */
final class RerankerException extends ContextEngineException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
