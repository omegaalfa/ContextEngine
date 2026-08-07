<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

/** Expõe identidade operacional do reranker para diagnóstico e comparação. */
interface IdentifiedReranker extends Reranker
{
    public function name(): string;

    public function provider(): ?string;

    public function model(): ?string;
}
