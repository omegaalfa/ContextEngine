<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Contract;

interface CacheableLanguageModel extends LanguageModel
{
    /** Complete identity including provider, model, and generation parameters. */
    public function generationFingerprint(): string;
}
