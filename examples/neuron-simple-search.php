<?php

declare(strict_types=1);

$startedAt = hrtime(true);

require dirname(__DIR__) . '/vendor/autoload.php';

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;

$env = static function (string $name, string $default): string {
    $value = getenv($name);
    return $value === false || trim($value) === '' ? $default : trim($value);
};

$question = trim(implode(' ', array_slice($argv, 1)));
$question = $question === ''
    ? 'Converta para PHP 8.4 a função Python optimal_bst presente no contexto.'
    : $question;
$documentsPath = $env('NEURON_DOCUMENTS_PATH', __DIR__ . '/documents');
$limit = (int) $env('NEURON_TOP_K', '5');
$chunkSize = (int) $env('NEURON_CHUNK_SIZE', '1000');
$wordOverlap = (int) $env('NEURON_WORD_OVERLAP', '25');

try {
    $embeddings = new OllamaEmbeddingsProvider(
        model: $env('NEURON_EMBEDDING_MODEL', 'bge-m3'),
        url: rtrim($env('NEURON_OLLAMA_URL', 'http://127.0.0.1:11434/api'), '/'),
    );
    $store = new MemoryVectorStore(topK: $limit);
    $loader = FileDataLoader::for($documentsPath)->withSplitter(
        new DelimiterTextSplitter(
            maxLength: $chunkSize,
            separator: ' ',
            wordOverlap: $wordOverlap,
        ),
    );
    $documents = $loader->getDocuments();
    if ($documents === []) {
        throw new RuntimeException('Nenhum documento foi carregado de ' . $documentsPath);
    }

    $store->addDocuments($embeddings->embedDocuments($documents));
    $results = new SimilarityRetrieval($store, $embeddings)
        ->retrieve(new UserMessage($question));

    echo 'Framework: Neuron AI ' . Composer\InstalledVersions::getPrettyVersion('neuron-core/neuron-ai') . PHP_EOL;
    echo 'Pergunta: ' . $question . PHP_EOL;
    echo 'Corpus: ' . $documentsPath . PHP_EOL;
    echo 'Aviso: MemoryVectorStore n�o filtra tenant ou collection.' . PHP_EOL . PHP_EOL;

    foreach ($results as $index => $result) {
        assert($result instanceof Document);
        printf(
            '#%d similaridade=%.6f distância=%.6f fonte=%s id=%s%s%s%s',
            $index + 1,
            $result->getScore(),
            1 - $result->getScore(),
            $result->getSourceName(),
            (string) $result->getId(),
            PHP_EOL,
            $result->getContent(),
            PHP_EOL . PHP_EOL,
        );
    }
    printf(
        'Execução: %.3f s | Pico de memória: %.2f MiB' . PHP_EOL,
        (hrtime(true) - $startedAt) / 1_000_000_000,
        memory_get_peak_usage(true) / 1_048_576,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'A busca com Neuron AI falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
