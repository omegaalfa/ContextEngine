<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

enum VectorMetric: string
{
    case L2 = 'l2';
    case INNER_PRODUCT = 'inner_product';
    case COSINE = 'cosine';
    case L1 = 'l1';
}
