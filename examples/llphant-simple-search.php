<?php

declare(strict_types=1);

$startedAt = hrtime(true);

require dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use LLPhant\Embeddings\DataReader\FileDataReader;
use LLPhant\Embeddings\Distances\CosineDistance;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\VectorStores\Memory\MemoryVectorStore;
use LLPhant\OllamaConfig;

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || trim($value) === '' ? $default : trim($value);
};
$ollamaApiUrl = static function (string $url): string {
    $url = rtrim($url, '/');

    return (str_ends_with($url, '/api') ? $url : $url . '/api') . '/';
};

$question = trim(implode(' ', array_slice($argv, 1)));
$question = $question === ''
    ? 'Converta para PHP 8.4 a função Python optimal_bst presente no contexto.'
    : $question;
$documentsPath = $env('LLPHANT_DOCUMENTS_PATH', __DIR__ . '/documents');
$topK = max(1, (int) $env('LLPHANT_TOP_K', '10'));
$maximumDistance = (float) $env('LLPHANT_MAXIMUM_DISTANCE', '0.60');
$finalLimit = max(1, (int) $env('LLPHANT_FINAL_LIMIT', '1'));
$chunkSize = max(1, (int) $env('LLPHANT_CHUNK_SIZE', '1000'));
$wordOverlap = max(0, (int) $env('LLPHANT_WORD_OVERLAP', '25'));

try {
    $embeddingConfig = new OllamaConfig();
    $embeddingConfig->model = $env('LLPHANT_EMBEDDING_MODEL', 'bge-m3');
    $embeddingConfig->url = $ollamaApiUrl($env('LLPHANT_OLLAMA_URL', 'http://127.0.0.1:11434'));
    $embeddingConfig->timeout = (float) $env('LLPHANT_OLLAMA_TIMEOUT', '300');

    $embeddings = new OllamaEmbeddingGenerator($embeddingConfig);
    $distance = new CosineDistance();
    $store = new MemoryVectorStore($distance);
    $documents = (new FileDataReader($documentsPath, extensions: ['txt', 'md']))->getDocuments();
    $chunks = DocumentSplitter::splitDocuments(
        documents: $documents,
        maxLength: $chunkSize,
        separator: ' ',
        wordOverlap: $wordOverlap,
    );
    if ($chunks === []) {
        throw new RuntimeException('Nenhum documento textual foi carregado de ' . $documentsPath . '.');
    }

    $store->addDocuments($embeddings->embedDocuments($chunks));
    $questionEmbedding = $embeddings->embedText($question);
    $candidates = $store->similaritySearch($questionEmbedding, $topK);

    $results = [];
    foreach ($candidates as $candidate) {
        if (!$candidate instanceof Document || $candidate->embedding === null) {
            continue;
        }

        $candidateDistance = $distance->measure($questionEmbedding, $candidate->embedding);
        if ($candidateDistance <= $maximumDistance) {
            $results[] = ['document' => $candidate, 'distance' => $candidateDistance];
        }
    }
    $results = array_slice($results, 0, $finalLimit);

    echo 'Framework: LLPhant ' . InstalledVersions::getPrettyVersion('theodo-group/llphant') . PHP_EOL;
    echo 'Pergunta: ' . $question . PHP_EOL;
    echo 'Corpus: ' . $documentsPath . PHP_EOL;
    printf('Pipeline: topK=%d -> distância<=%.2f -> limite final=%d%s%s', $topK, $maximumDistance, $finalLimit, PHP_EOL, PHP_EOL);

    if ($results === []) {
        echo 'Não há evidências suficientes no corpus.' . PHP_EOL;
    }

    foreach ($results as $index => $result) {
        /** @var Document $document */
        $document = $result['document'];
        printf(
            '#%d distância=%.6f fonte=%s chunk=%d hash=%s%s%s%s%s',
            $index + 1,
            $result['distance'],
            $document->sourceName,
            $document->chunkNumber,
            $document->hash,
            PHP_EOL,
            $document->content,
            PHP_EOL,
            PHP_EOL,
        );
    }

    printf(
        'Execução: %.3f s | Pico de memória: %.2f MiB' . PHP_EOL,
        (hrtime(true) - $startedAt) / 1_000_000_000,
        memory_get_peak_usage(true) / 1_048_576,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'A busca com LLPhant falhou: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
