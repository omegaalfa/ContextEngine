<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Support;

final readonly class PortugueseTextAnalysisProfile implements TextAnalysisProfile
{
    public function claims(string $text): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split(
                '/(?<=[.!?;])(?<!\d[.!?;])\s+|\R+|\s+(?-i:e)\s+(?=(?:foi|é|era|possui|tem|representa|criou|aceita)\b)/u',
                trim($text),
            ) ?: [],
        ), static fn (string $claim): bool => $claim !== ''));
    }

    public function isNegated(string $normalizedText): bool
    {
        return preg_match('/\b(?:não|nunca|jamais|sem)\b/u', $normalizedText) === 1;
    }

    public function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?;])(?<!\d[.!?;])\s+|\R+/u', $text) ?: [];
    }
}