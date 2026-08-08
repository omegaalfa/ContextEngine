<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Support;

interface TextAnalysisProfile
{
    /** @return list<string> */
    public function claims(string $text): array;

    public function isNegated(string $normalizedText): bool;

    /** @return list<string> */
    public function sentences(string $text): array;
}
