<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

enum IngestionState: string
{
    case STAGED = 'staged';
    case ACTIVE = 'active';
    case FAILED = 'failed';
    case SUPERSEDED = 'superseded';
}
