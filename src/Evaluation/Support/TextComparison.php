<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Support;

final class TextComparison
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
