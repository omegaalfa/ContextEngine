<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Provider\Cohere;

use Omegaalfa\ContextEngine\Contract\IdentifiedReranker;
use Omegaalfa\ContextEngine\Exception\ProviderException;
use Omegaalfa\ContextEngine\Exception\RerankerException;
use Omegaalfa\ContextEngine\Provider\Http\JsonClient;
use Omegaalfa\ContextEngine\Provider\Support\ProviderConfiguration;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use Throwable;

/** Adapter específico para a API Cohere Rerank v2. */
final readonly class CohereReranker implements IdentifiedReranker
{
    private JsonClient $http;
    private string $baseUrl;
    public string $model;

    public function __construct(
        string $apiKey,
        string $model = 'rerank-v4.0-pro',
        AsyncHttpClient $client = new AsyncHttpClient(),
        string $baseUrl = 'https://api.cohere.com/v2',
        float $timeoutSeconds = 10.0,
    ) {
        $apiKey = ProviderConfiguration::nonEmpty($apiKey, 'Cohere API key');
        $this->model = ProviderConfiguration::nonEmpty($model, 'Cohere rerank model');
        $this->baseUrl = ProviderConfiguration::baseUrl($baseUrl);
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Cohere reranker timeout must be positive and finite.');
        }
        $this->http = new JsonClient($client->withBearerToken($apiKey)->withTimeout($timeoutSeconds));
    }

    public function name(): string { return 'CohereReranker'; }

    public function provider(): string { return 'cohere'; }

    public function model(): string { return $this->model; }

    public function rerank(Question $question, array $results): array
    {
        if ($results === []) {
            return [];
        }
        try {
            $response = $this->http->post($this->baseUrl.'/rerank', [
                'model' => $this->model,
                'query' => $question->content,
                'documents' => array_map(static fn (VectorSearchResult $result): string => $result->chunk->content, $results),
                'top_n' => count($results),
            ]);
            return $this->ordered($results, $response['results'] ?? null);
        } catch (ProviderException $exception) {
            throw new RerankerException(
                'Cohere reranker request failed.',
                self::causedByTimeout($exception),
                $exception,
            );
        }
    }

    /**
     * @param list<VectorSearchResult> $candidates
     * @return list<VectorSearchResult>
     */
    private function ordered(array $candidates, mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($candidates)) {
            throw new RerankerException('Cohere reranker returned an incomplete ranking.');
        }
        $ranked = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['index'], $row['relevance_score'])
                || !is_int($row['index']) || !is_int($row['relevance_score']) && !is_float($row['relevance_score'])) {
                throw new RerankerException('Cohere reranker returned an invalid result.');
            }
            $index = $row['index'];
            $score = (float) $row['relevance_score'];
            if (!isset($candidates[$index]) || isset($seen[$index]) || !is_finite($score) || $score < 0 || $score > 1) {
                throw new RerankerException('Cohere reranker returned invalid indexes or scores.');
            }
            $seen[$index] = true;
            $result = $candidates[$index];
            $ranked[] = new VectorSearchResult(
                chunk: $result->chunk,
                distance: $result->distance,
                documentVersion: $result->documentVersion,
                neighbor: $result->neighbor,
                fusionScore: $result->fusionScore,
                matches: $result->matches,
                provenance: $result->provenance,
                lexicalScore: $result->lexicalScore,
                rerankerScore: $score,
            );
        }
        return $ranked;
    }

    private static function causedByTimeout(Throwable $exception): bool
    {
        do {
            if ($exception instanceof TimeoutException) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);
        return false;
    }
}
