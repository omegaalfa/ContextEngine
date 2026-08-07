<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\VectorStore;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Evaluation\AnswerEvaluationPolicy;
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;
use Omegaalfa\ContextEngine\Evaluation\ExpectedClaim;
use Omegaalfa\ContextEngine\Evaluation\GroundednessResult;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

$space = new EmbeddingSpace('evaluation-demo', 'deterministic', 1);
$embeddings = new class ($space) implements EmbeddingProvider {
    public function __construct(private readonly EmbeddingSpace $space) {}
    public function space(): EmbeddingSpace { return $this->space; }
    public function embed(string $text, string $tenantId): Embedding { return new Embedding([1.0], $this->space); }
    public function embedBatch(EmbeddingBatchRequest $request): array { return []; }
};

$results = [
    new VectorSearchResult(new Chunk('bellman', 'algorithms', 'demo', 'O algoritmo Bellman-Ford possui complexidade O(VE) e aceita pesos negativos.', 0), 0.1),
    new VectorSearchResult(new Chunk('avl', 'algorithms', 'demo', 'Uma árvore AVL mantém o balanceamento por meio de rotações.', 1), 0.2),
    new VectorSearchResult(new Chunk('optimal-bst', 'optimal-bst', 'demo', 'w[i][j] representa a soma das probabilidades no intervalo do Optimal BST.', 0), 0.3),
];
$store = new class ($results) implements VectorStore {
    /** @param list<VectorSearchResult> $results */
    public function __construct(private readonly array $results) {}
    /** @param list<EmbeddedChunk> $chunks */
    public function storeBatch(array $chunks): void {}
    public function search(VectorSearchQuery $query): array { return $this->results; }
    public function deleteChunk(ChunkDeleteQuery $query): int { return 0; }
    public function deleteDocument(DocumentDeleteQuery $query): int { return 0; }
    public function clearCollection(CollectionDeleteQuery $query): int { return 0; }
};
$model = new class () implements LanguageModel {
    public function complete(array $messages): string
    {
        $prompt = implode("\n", array_map(static fn ($message): string => $message->content, $messages));
        preg_match('/<QUESTION>\s*(.*?)\s*<\/QUESTION>/su', $prompt, $matches);
        $question = mb_strtolower($matches[1] ?? '');
        if (str_contains($question, 'adversarial: negação')) {
            return 'Bellman-Ford não possui complexidade O(VE).';
        }
        if (str_contains($question, 'adversarial: complexidade alterada')) {
            return 'Bellman-Ford possui complexidade O(V²).';
        }
        if (str_contains($question, 'adversarial: entidade trocada')) {
            return 'e[i][j] representa a soma das probabilidades no intervalo do Optimal BST.';
        }
        if (str_contains($question, 'adversarial: claim mista')) {
            return 'Bellman-Ford tem complexidade O(VE) e foi criado por Wesley em 1999.';
        }
        if (str_contains($question, 'wesley')) {
            return 'Wesley criou o Bellman-Ford em 1999.';
        }
        if (str_contains($question, 'avl')) {
            return 'Uma árvore AVL mantém o balanceamento por meio de rotações.';
        }
        return 'O Bellman-Ford possui complexidade O(VE).';
    }
};

$pipeline = new RagPipeline(new Retriever($embeddings, $store), new ContextPromptBuilder(), $model);
$dataset = new EvaluationDataset([
    new EvaluationCase(
        id: 'Bellman-Ford direto',
        question: 'Qual é a complexidade do Bellman-Ford?',
        relevantDocumentIds: ['algorithms'],
        expectedTerms: ['pesos negativos'],
        expectedClaims: [new ExpectedClaim('complexity', ['O(VE)', 'O(V * E)', 'O(|V||E|)'])],
    ),
    new EvaluationCase(
        id: 'AVL sem gabarito factual',
        question: 'O que é uma árvore AVL?',
        relevantDocumentIds: ['algorithms'],
        expectedTerms: ['AVL', 'rotações'],
    ),
    new EvaluationCase(
        id: 'Afirmação inventada',
        question: 'Quem criou o algoritmo Wesley?',
        relevantDocumentIds: ['algorithms'],
    ),
    new EvaluationCase(
        id: 'Adversarial: negação de fato correto',
        question: 'Adversarial: negação. Qual é a complexidade do Bellman-Ford?',
        relevantDocumentIds: ['algorithms'],
    ),
    new EvaluationCase(
        id: 'Adversarial: número ou complexidade alterada',
        question: 'Adversarial: complexidade alterada. Qual é a complexidade do Bellman-Ford?',
        relevantDocumentIds: ['algorithms'],
    ),
    new EvaluationCase(
        id: 'Adversarial: entidade trocada',
        question: 'Adversarial: entidade trocada. O que representa w[i][j] no Optimal BST?',
        relevantDocumentIds: ['optimal-bst'],
    ),
    new EvaluationCase(
        id: 'Adversarial: claim apoiada e claim inventada',
        question: 'Adversarial: claim mista. Informe a complexidade e a autoria do Bellman-Ford.',
        relevantDocumentIds: ['algorithms'],
    ),
], 'Qualidade determinística da resposta');

$report = new RagEvaluator(
    tenantId: 'demo',
    policy: new AnswerEvaluationPolicy(requireExpectedTerms: false),
)->evaluate($pipeline, $dataset);

echo PHP_EOL.str_repeat('=', 68).PHP_EOL;
echo '  ContextEngine - Avaliação determinística da resposta'.PHP_EOL;
echo str_repeat('=', 68).PHP_EOL;
echo 'Dataset: '.$report->datasetName.PHP_EOL;
echo 'Resultado: '.$report->passedCases.'/'.$report->executedCases.' casos aprovados'.PHP_EOL;

foreach ($report->results as $result) {
    echo PHP_EOL.str_repeat('-', 68).PHP_EOL;
    echo ($result->passed ? '✔' : '✘').' '.$result->case->id.PHP_EOL;
    echo 'Pergunta: '.($result->case->question instanceof \Omegaalfa\ContextEngine\Rag\Question ? $result->case->question->content : $result->case->question).PHP_EOL;
    echo 'Resposta: '.$result->execution?->answer->content.PHP_EOL;
    foreach (['groundedness', 'answer_relevance', 'correctness', 'contains_expected_terms'] as $metric) {
        $score = $result->scores[$metric] ?? null;
        echo str_pad($metric.' ', 29, '.').' '.($score === null ? 'N/A' : number_format($score->value, 2).' '.($score->passed ? '✔' : '✘')).PHP_EOL;
        if ($score?->details instanceof GroundednessResult) {
            foreach ($score->details->supportedClaims as $claim) {
                echo '  [apoiada] '.$claim['claim'].' -> '.$claim['chunkId'].PHP_EOL;
            }
            foreach ($score->details->unsupportedClaims as $claim) {
                echo '  [sem apoio] '.$claim.PHP_EOL;
            }
        }
    }
}

echo PHP_EOL.'Execute novamente com:'.PHP_EOL;
echo 'php examples/evaluation/evaluate-answer-quality.php'.PHP_EOL.PHP_EOL;
