<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Exception;

final class IncompatibleVectorStoreSchemaException extends ContextEngineException
{
    /**
     * @param list<string> $missingColumns
     * @param \Throwable|null $previous
     */
    public function __construct(
        array $missingColumns = [],
        ?\Throwable $previous = null,
    ) {
        $missing = $missingColumns === [] ? 'required vector store columns' : implode(', ', $missingColumns);
        parent::__construct(
            sprintf(
                'Vector store schema is incompatible with the current ContextEngine version. Apply the pending database migrations before retrying. Missing columns: %s.',
                $missing,
            ),
            0,
            $previous,
        );
    }
}
