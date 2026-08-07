<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

/**
 * Explica a nota de apoio textual, separando afirmações apoiadas e sem apoio.
 *
 * A rastreabilidade permite descobrir qual chunk sustentou cada afirmação em
 * vez de oferecer apenas uma nota sem explicação.
 */
final readonly class GroundednessResult
{
    /**
     * @param list<array{claim:string,evidence:string,chunkId:string}> $supportedClaims
     * @param list<string> $unsupportedClaims
     */
    public function __construct(
        public float $score,
        public array $supportedClaims,
        public array $unsupportedClaims,
    ) {}
}
