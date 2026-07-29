<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Support;

final class TextNormalizer
{
    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }
}
