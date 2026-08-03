<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\NoEvidencePolicy;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\InsufficientContextException;
use Omegaalfa\ContextEngine\Exception\StreamingNotSupportedException;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

final readonly class RagPipeline
{
    private NoEvidencePolicy $noEvidencePolicy;
    /**
     * @param Retriever $retriever
     * @param ContextPromptBuilder $prompts
     * @param LanguageModel $model
     * @param StreamingLanguageModel|null $streamingModel
     */
    public function __construct(
        private Retriever               $retriever,
        private ContextPromptBuilder    $prompts,
        private LanguageModel           $model,
        private ?StreamingLanguageModel $streamingModel = null,
        ?NoEvidencePolicy $noEvidencePolicy = null,
    ) {
        $this->noEvidencePolicy = $noEvidencePolicy ?? new FixedNoEvidencePolicy();
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return Answer
     * @throws JsonException
     */
    public function ask(Question|string $question, ?string $tenantId = null): Answer
    {
        return $this->askWithDiagnostics($question, $tenantId)->answer;
    }

    public function askWithDiagnostics(Question|string $question, ?string $tenantId = null): RagExecution
    {
        $totalStarted = hrtime(true);
        $question = $this->question($question, $tenantId);
        $retrieval = $this->retriever->retrieveWithDiagnostics($question);
        if ($retrieval->results === []) {
            return new RagExecution(
                new Answer($this->noEvidencePolicy->response($question), []),
                new RagDiagnostics(
                    $retrieval->diagnostics,
                    0,
                    false,
                    [
                        'promptBuilding' => 0.0,
                        'model' => 0.0,
                        'total' => self::elapsed($totalStarted),
                    ],
                ),
            );
        }
        $promptStarted = hrtime(true);
        $messages = $this->prompts->build($question, $retrieval->results);
        $promptTime = self::elapsed($promptStarted);
        $modelStarted = hrtime(true);
        $content = $this->model->complete($messages);
        $modelTime = self::elapsed($modelStarted);
        $answer = new Answer($content, $retrieval->results);
        $promptCharacters = array_sum(array_map(
            static fn ($message): int => mb_strlen($message->content),
            $messages,
        ));
        return new RagExecution($answer, new RagDiagnostics(
            $retrieval->diagnostics,
            $promptCharacters,
            true,
            [
                'promptBuilding' => $promptTime,
                'model' => $modelTime,
                'total' => self::elapsed($totalStarted),
            ],
        ));
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return iterable<AnswerDelta>
     * @throws JsonException
     */
    public function stream(Question|string $question, ?string $tenantId = null): iterable
    {
        $question = $this->question($question, $tenantId);
        $sources = $this->retriever->retrieve($question);
        if ($sources === []) {
            throw new InsufficientContextException($this->noEvidencePolicy->response($question));
        }
        if ($this->streamingModel === null) {
            throw new StreamingNotSupportedException('No incremental streaming language model is configured.');
        }
        yield from $this->streamingModel->stream($this->prompts->build($question, $sources));
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return Question
     */
    private function question(Question|string $question, ?string $tenantId): Question
    {
        if ($question instanceof Question) {
            return $question;
        }
        if ($tenantId === null) {
            throw new InvalidArgumentException('tenantId is required when question is a string.');
        }
        return new Question($question, $tenantId);
    }

    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
