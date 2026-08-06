<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Ingestion\Parser;

use InvalidArgumentException;

final readonly class StructuralNoisePolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $minimumAlphabeticCharacters = 2,
        public float $minimumTextRatio = 0.25,
        public int $minimumBlockLength = 3,
        public float $maximumIsolatedTokenRatio = 0.60,
        public int $maximumConsecutiveSpaceGroups = 2,
    ) {
        if ($minimumAlphabeticCharacters < 0 || $minimumBlockLength < 0 || $maximumConsecutiveSpaceGroups < 0) {
            throw new InvalidArgumentException('Structural noise integer limits must be non-negative.');
        }
        if ($minimumTextRatio < 0.0 || $minimumTextRatio > 1.0 || $maximumIsolatedTokenRatio < 0.0 || $maximumIsolatedTokenRatio > 1.0) {
            throw new InvalidArgumentException('Structural noise ratios must be between zero and one.');
        }
    }

    public function classify(string $content): StructuralNoiseDecision
    {
        if (!$this->enabled) {
            return new StructuralNoiseDecision(StructuralNoiseKind::CONTENT);
        }

        $content = trim($content);
        if ($content === '' || mb_strlen($content) < $this->minimumBlockLength) {
            return new StructuralNoiseDecision(StructuralNoiseKind::UNKNOWN, true, 'block_too_short', 0.95);
        }

        $compact = preg_replace('/\s+/u', '', $content) ?? '';
        $alphabetic = $this->count('/\p{L}/u', $compact);
        $digits = $this->count('/\p{N}/u', $compact);
        $visible = max(1, mb_strlen($compact));
        $textRatio = $alphabetic / $visible;
        $tokens = preg_split('/\s+/u', $content, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $isolated = array_filter($tokens, static fn (string $token): bool => mb_strlen(trim($token, '.,:;|()[]{}')) === 1);
        $isolatedRatio = count($tokens) === 0 ? 0.0 : count($isolated) / count($tokens);
        $naturalWords = array_filter($tokens, static fn (string $token): bool => preg_match('/\p{L}{3,}/u', $token) === 1);
        $spaceGroups = $this->count('/\h{2,}/u', $content);

        if ($digits >= 3 && $alphabetic === 0 && preg_match('/^[\p{N}\s.,;:+\-]+$/u', $content) === 1) {
            return new StructuralNoiseDecision(StructuralNoiseKind::DIAGRAM_TEXT, true, 'numeric_only_layout', 1.0);
        }

        $lines = preg_split('/\R/u', $content, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $isolatedLines = array_filter($lines, static fn (string $line): bool => preg_match('/^[\p{L}\p{N}]$/u', trim($line)) === 1);
        if (count($lines) >= 3 && count($isolatedLines) === count($lines)) {
            return new StructuralNoiseDecision(StructuralNoiseKind::FIGURE_TEXT, true, 'isolated_character_lines', 1.0);
        }

        if (count($tokens) >= 3 && $isolatedRatio >= $this->maximumIsolatedTokenRatio && count($naturalWords) === 0) {
            return new StructuralNoiseDecision(StructuralNoiseKind::DIAGRAM_TEXT, true, 'isolated_token_sequence', 0.98);
        }

        if ($spaceGroups > $this->maximumConsecutiveSpaceGroups && count($naturalWords) < 2 && $isolatedRatio >= 0.40) {
            return new StructuralNoiseDecision(StructuralNoiseKind::DIAGRAM_TEXT, true, 'sparse_layout_columns', 0.95);
        }

        if ($alphabetic < $this->minimumAlphabeticCharacters && ($digits > 0 || $textRatio < $this->minimumTextRatio)) {
            return new StructuralNoiseDecision(StructuralNoiseKind::UNKNOWN, true, 'insufficient_alphabetic_content', 0.90);
        }

        if ($textRatio < $this->minimumTextRatio && count($naturalWords) < 2) {
            return new StructuralNoiseDecision(StructuralNoiseKind::UNKNOWN, true, 'low_natural_language_density', 0.85);
        }

        return new StructuralNoiseDecision(StructuralNoiseKind::CONTENT);
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            'structural-noise-policy',
            '1',
            $this->enabled ? '1' : '0',
            (string) $this->minimumAlphabeticCharacters,
            (string) $this->minimumTextRatio,
            (string) $this->minimumBlockLength,
            (string) $this->maximumIsolatedTokenRatio,
            (string) $this->maximumConsecutiveSpaceGroups,
        ]));
    }

    private function count(string $pattern, string $content): int
    {
        $matches = [];
        $result = preg_match_all($pattern, $content, $matches);

        return $result === false ? 0 : $result;
    }
}
