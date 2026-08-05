<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\HighLevel;

/**
 * @deprecated Use Omegaalfa\ContextEngine\ContextEngine.
 */
if (!class_exists(__NAMESPACE__ . '\\ContextEngine', false)) {
    class_alias(\Omegaalfa\ContextEngine\ContextEngine::class, __NAMESPACE__ . '\\ContextEngine');
}
