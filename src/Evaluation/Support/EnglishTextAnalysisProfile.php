<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Support;

final readonly class EnglishTextAnalysisProfile implements TextAnalysisProfile
{
    public function claims(string $text): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split(
                '/(?<=[.!?;])(?<!\d[.!?;])\s+|\R+|\s+(?-i:and)\s+(?=(?:is|was|has|does|represents|created|accepts)\b)/u',
                trim($text),
            ) ?: [],
        ), static fn(string $claim): bool => $claim !== ''));
    }

    public function isNegated(string $normalizedText): bool
    {
        return preg_match('/\b(?:is not|was not|has no|does not have|never|without)\b/u', $normalizedText) === 1;
    }

    public function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?;])(?<!\d[.!?;])\s+|\R+/u', $text) ?: [];
    }
}