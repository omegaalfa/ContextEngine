<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Support;

/** Extrai termos úteis e identificadores protegidos para avaliação determinística. */
final class SignificantTerms
{
    private const STOP_WORDS = [
        'a', 'ao', 'aos', 'as', 'como', 'da', 'das', 'de', 'do', 'dos', 'e', 'ela', 'ele', 'em', 'é',
        'explique', 'funciona', 'o', 'os', 'para', 'pergunta', 'por', 'qual', 'que', 'se', 'serve', 'um', 'uma',
    ];

    /**
     * Retorna palavras informativas, removendo conectivos e termos genéricos.
     *
     * @return list<string>
     */
    public static function from(string $text): array
    {
        $normalized = TextComparison::normalize($text);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool =>
            !in_array($token, self::STOP_WORDS, true) && (mb_strlen($token) >= 3 || preg_match('/\d/u', $token) === 1)
        )));
    }

    /**
     * Retorna números, fórmulas e identificadores que não podem ser trocados.
     *
     * @return list<string>
     */
    public static function protected(string $text): array
    {
        preg_match_all('/\bO\([^\)]+\)|\b\d+(?:[.,]\d+)?\b|\b[A-Z][A-Za-z0-9_-]{1,}(?:\[[^\]]+\])?|\b[A-Za-z]\[[^\]]+\]/u', $text, $matches);
        $ignored = ['A', 'As', 'O', 'Os', 'Um', 'Uma'];
        return array_values(array_unique(array_map(
            [TextComparison::class, 'normalize'],
            array_filter($matches[0], static fn (string $term): bool => !in_array($term, $ignored, true)),
        )));
    }
}
