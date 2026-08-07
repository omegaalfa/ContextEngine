<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation\Evaluator;

use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\Metrics\EvaluationScore;
use Omegaalfa\ContextEngine\Rag\RagExecution;

/**
 * Contrato para métricas de resposta independentes de qualquer provider de IA.
 *
 * Retorne null quando o caso não possuir informação suficiente para aplicar a
 * métrica; ausência de gabarito não deve ser transformada em nota zero.
 */
interface AnswerEvaluator
{
    /** Avalia uma resposta já produzida ou retorna null quando não aplicável. */
    public function evaluate(EvaluationCase $case, RagExecution $execution): ?EvaluationScore;
}
