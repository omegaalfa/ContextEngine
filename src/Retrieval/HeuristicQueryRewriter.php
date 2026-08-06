<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Retrieval;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\QueryRewriter;
use Omegaalfa\ContextEngine\Rag\Question;

final readonly class HeuristicQueryRewriter implements QueryRewriter
{
    /**
     * @param int $maximumQueries
     */
    public function __construct(private int $maximumQueries = 4)
    {
        if ($maximumQueries < 1 || $maximumQueries > 10) {
            throw new InvalidArgumentException('Maximum queries must be between one and ten.');
        }
    }

    /**
     * @param Question $question
     * @return RewrittenQueries
     */
    public function rewrite(Question $question): RewrittenQueries
    {
        $original = $question->content;
        $terms = [];
        preg_match_all('/\x60([^\x60]+)\x60|(?<![\pL\pN])([\pL][\pL\pN]*(?:[_-][\pL\pN]+)+)|\b([A-Z][A-Z0-9_-]{2,})\b|([a-zA-Z]\[[^\]\r\n]{1,24}\])/u', $original, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            foreach (array_slice($match, 1) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && mb_strtolower($candidate) !== mb_strtolower($original)) {
                    $terms[$candidate] = true;
                }
            }
        }
        preg_match_all('/\b[A-Z][a-z]{3,}\b/u', $original, $properNames);
        $proper = array_values(array_unique($properNames[0]));
        foreach ($proper as $index => $candidate) {
            if ($index === 0 && (count($proper) > 1 || $terms !== [])) {
                continue;
            }
            foreach (array_keys($terms) as $technicalTerm) {
                if (mb_stripos($technicalTerm, $candidate) !== false) {
                    continue 2;
                }
            }
            $terms[$candidate] = true;
        }
        $queries = [$original];
        foreach (array_keys($terms) as $term) {
            $queries[] = $term;
            if (count($queries) >= $this->maximumQueries) {
                break;
            }
        }
        if (count($terms) > 1 && count($queries) < $this->maximumQueries) {
            $combined = implode(' ', array_keys($terms));
            if (!in_array($combined, $queries, true)) {
                $queries[] = $combined;
            }
        }
        return new RewrittenQueries($original, $queries);
    }
}
