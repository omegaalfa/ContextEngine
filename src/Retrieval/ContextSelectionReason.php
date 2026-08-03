<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

enum ContextSelectionReason: string
{
    case PRIMARY_EVIDENCE = 'primary_evidence';
    case SAME_DOCUMENT_SUPPORT = 'same_document_support';
    case NEIGHBOR_CONTEXT = 'neighbor_context';
    case ADDITIONAL_COVERAGE = 'additional_coverage';
    case DISTANCE_GAP = 'distance_gap';
    case DUPLICATE_EVIDENCE = 'duplicate_evidence';
    case SOURCE_LIMIT = 'source_limit';
    case CONTEXT_BUDGET = 'context_budget';
}
