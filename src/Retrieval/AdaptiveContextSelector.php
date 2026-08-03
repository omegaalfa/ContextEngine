<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

/**
 * Deterministic local selection. Additional coverage means significant terms
 * from the question that were not present in previously selected evidence.
 */
final readonly class AdaptiveContextSelector
{
    /** @var array<string, true> */
    private const STOP_WORDS = [
        'com' => true, 'como' => true, 'das' => true, 'dos' => true,
        'essa' => true, 'esse' => true, 'esta' => true, 'este' => true,
        'nas' => true, 'nos' => true, 'para' => true, 'por' => true,
        'que' => true, 'uma' => true, 'and' => true, 'for' => true,
        'from' => true, 'how' => true, 'the' => true, 'what' => true,
        'with' => true,
    ];

    public function __construct(private ContextRelevancePolicy $policy) {}

    /**
     * @param list<VectorSearchResult> $candidates
     * @return array{selected: list<VectorSearchResult>, decisions: list<ContextSelectionDiagnostic>}
     */
    public function select(string $question, array $candidates): array
    {
        if ($candidates === []) {
            return ['selected' => [], 'decisions' => []];
        }

        $primary = $this->primary($candidates);
        $questionTerms = self::terms($question);
        $covered = self::intersection($questionTerms, self::terms($primary->chunk->content));
        $selectedIds = [$primary->chunk->id => true];
        $reasons = [$primary->chunk->id => ContextSelectionReason::PRIMARY_EVIDENCE];
        $selectedContents = [self::terms($primary->chunk->content)];

        foreach ($this->orderedCandidates($candidates, $primary) as $candidate) {
            $id = $candidate->chunk->id;
            if (isset($selectedIds[$id])) {
                continue;
            }
            if (count($selectedIds) >= $this->policy->maximumSources) {
                $reasons[$id] = ContextSelectionReason::SOURCE_LIMIT;
                continue;
            }
            $minimumNeeded = count($selectedIds) < $this->policy->minimumSources;
            $terms = self::terms($candidate->chunk->content);
            if (!$minimumNeeded && $this->redundant($terms, $selectedContents)) {
                $reasons[$id] = ContextSelectionReason::DUPLICATE_EVIDENCE;
                continue;
            }

            $sameDocument = $candidate->chunk->documentId === $primary->chunk->documentId;
            $newCoverage = self::difference(self::intersection($questionTerms, $terms), $covered);
            if ($candidate->neighbor && $sameDocument && ($newCoverage !== [] || $minimumNeeded)) {
                $reason = ContextSelectionReason::NEIGHBOR_CONTEXT;
            } elseif ($newCoverage !== []) {
                $reason = $sameDocument && $this->policy->preferSameDocument
                    ? ContextSelectionReason::SAME_DOCUMENT_SUPPORT
                    : ContextSelectionReason::ADDITIONAL_COVERAGE;
            } elseif ($minimumNeeded) {
                $reason = $sameDocument && $this->policy->preferSameDocument
                    ? ContextSelectionReason::SAME_DOCUMENT_SUPPORT
                    : ContextSelectionReason::ADDITIONAL_COVERAGE;
            } elseif ($candidate->distance - $primary->distance > $this->policy->maximumDistanceGap) {
                $reasons[$id] = ContextSelectionReason::DISTANCE_GAP;
                continue;
            } else {
                $reasons[$id] = ContextSelectionReason::DUPLICATE_EVIDENCE;
                continue;
            }

            $selectedIds[$id] = true;
            $reasons[$id] = $reason;
            $selectedContents[] = $terms;
            $covered += $newCoverage;
        }

        $selected = [];
        $decisions = [];
        foreach ($candidates as $candidate) {
            $id = $candidate->chunk->id;
            $isSelected = isset($selectedIds[$id]);
            if ($isSelected) {
                $selected[] = $candidate;
            }
            $decisions[] = new ContextSelectionDiagnostic(
                $id,
                $isSelected,
                $reasons[$id] ?? ContextSelectionReason::SOURCE_LIMIT,
            );
        }
        return ['selected' => $selected, 'decisions' => $decisions];
    }

    /** @param list<VectorSearchResult> $candidates */
    private function primary(array $candidates): VectorSearchResult
    {
        foreach ($candidates as $candidate) {
            if (!$candidate->neighbor) {
                return $candidate;
            }
        }
        return $candidates[0];
    }

    /**
     * @param list<VectorSearchResult> $candidates
     * @return list<VectorSearchResult>
     */
    private function orderedCandidates(array $candidates, VectorSearchResult $primary): array
    {
        if (!$this->policy->preferSameDocument) {
            return $candidates;
        }
        $same = [];
        $other = [];
        foreach ($candidates as $candidate) {
            if ($candidate->chunk->documentId === $primary->chunk->documentId) {
                $same[] = $candidate;
            } else {
                $other[] = $candidate;
            }
        }
        return [...$same, ...$other];
    }

    /**
     * @param array<string, true> $terms
     * @param list<array<string, true>> $selected
     */
    private function redundant(array $terms, array $selected): bool
    {
        foreach ($selected as $existing) {
            $union = $terms + $existing;
            if ($union !== []
                && count(self::intersection($terms, $existing)) / count($union) >= 0.85) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, true> */
    private static function terms(string $text): array
    {
        $parts = preg_split('/[^\\p{L}\\p{N}_-]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3 && !isset(self::STOP_WORDS[$part])) {
                $terms[$part] = true;
            }
        }
        return $terms;
    }

    /**
     * @param array<string, true> $left
     * @param array<string, true> $right
     * @return array<string, true>
     */
    private static function intersection(array $left, array $right): array
    {
        return array_intersect_key($left, $right);
    }

    /**
     * @param array<string, true> $left
     * @param array<string, true> $right
     * @return array<string, true>
     */
    private static function difference(array $left, array $right): array
    {
        return array_diff_key($left, $right);
    }
}
