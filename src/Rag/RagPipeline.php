<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Rag;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Exception\StreamingNotSupportedException;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

final readonly class RagPipeline
{
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
        private ?StreamingLanguageModel $streamingModel = null
    )
    {
    }

    /**
     * @param Question|string $question
     * @param string|null $tenantId
     * @return Answer
     * @throws JsonException
     */
    public function ask(Question|string $question, ?string $tenantId = null): Answer
    {
        $question = $this->question($question, $tenantId);
        $sources = $this->retriever->retrieve($question);
        return new Answer($this->model->complete($this->prompts->build($question, $sources)), $sources);
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
}
