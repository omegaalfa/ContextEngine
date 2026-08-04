<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion;

enum DocumentVersionStatus: string
{
    case DRAFT = 'draft';
    case STAGED = 'staged';
    case ACTIVE = 'active';
    case SUPERSEDED = 'superseded';
    case ARCHIVED = 'archived';
}
