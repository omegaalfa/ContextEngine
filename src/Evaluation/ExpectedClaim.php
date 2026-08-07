<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use InvalidArgumentException;

/**
 * Representa um fato do gabarito e as diferentes formas aceitas de escrevê-lo.
 *
 * Use claims para avaliar fatos pontuais sem exigir que a resposta inteira seja
 * idêntica a um texto de referência.
 */
final readonly class ExpectedClaim
{
    /** @param list<string> $alternatives */
    public function __construct(public string $id, public array $alternatives)
    {
        if (trim($id) === '' || $alternatives === [] || array_any($alternatives, static fn (string $value): bool => trim($value) === '')) {
            throw new InvalidArgumentException('Expected claims require an id and non-empty alternatives.');
        }
    }
}
