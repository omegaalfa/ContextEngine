<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Chunk\Chunk;
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Contract\NeighborAwareVectorStore;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;
use Omegaalfa\ContextEngine\Embedding\EmbeddedChunk;
use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingBatchRequest;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Prompt\ChatMessage;
use Omegaalfa\ContextEngine\Rag\AnswerDelta;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\NeighborSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\RetrievalDiagnostics;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchResult;
use Omegaalfa\ContextEngine\VectorStore\ChunkDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\CollectionDeleteQuery;
use Omegaalfa\ContextEngine\VectorStore\DocumentDeleteQuery;

if (!function_exists('demo_environment')) {
    /**
     * @return array{tenant:string,collection:string,embeddings:DemoEmbeddingProvider,store:DemoInMemoryStore}
     */
    function demo_environment(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $tenant = 'acme-support';
        $collection = 'knowledge-base';
        $embeddings = new DemoEmbeddingProvider();
        $store = new DemoInMemoryStore();

        $rows = [
            [
                'id' => 'policy-001',
                'documentId' => 'refund-policy',
                'position' => 0,
                'content' => 'Refund requests are accepted up to 30 calendar days after payment confirmation.',
            ],
            [
                'id' => 'policy-002',
                'documentId' => 'refund-policy',
                'position' => 1,
                'content' => 'For digital goods, the policy requires order id, payment proof, and the reason code.',
            ],
            [
                'id' => 'policy-003',
                'documentId' => 'refund-policy',
                'position' => 2,
                'content' => 'Requests after 30 days are denied unless there is legal obligation or duplicate charge evidence.',
            ],
            [
                'id' => 'pay-err-001',
                'documentId' => 'payment-errors',
                'position' => 0,
                'content' => 'ERR_PAYMENT_1047 means card token expired before capture and requires token refresh.',
            ],
            [
                'id' => 'pay-err-002',
                'documentId' => 'payment-errors',
                'position' => 1,
                'content' => 'Class PaymentGatewayService handles ERR_PAYMENT_1047 by calling renewToken() and retrying chargeOrder().',
            ],
            [
                'id' => 'pay-err-003',
                'documentId' => 'payment-errors',
                'position' => 2,
                'content' => 'When renewToken() fails, return error mapping PAYMENT_RETRY_REQUIRED and stop capture flow.',
            ],
            [
                'id' => 'sku-001',
                'documentId' => 'inventory-guide',
                'position' => 0,
                'content' => 'SKU AX9-RED belongs to premium headset line and uses warehouse class InventoryAllocator.',
            ],
            [
                'id' => 'sku-002',
                'documentId' => 'inventory-guide',
                'position' => 1,
                'content' => 'Function reserveSkuQuantity(sku, qty) validates stock before checkout lock.',
            ],
            [
                'id' => 'sku-003',
                'documentId' => 'inventory-guide',
                'position' => 2,
                'content' => 'If AX9-RED stock is below threshold, notify purchasing bot with event SKU_LOW_STOCK.',
            ],
            [
                'id' => 'code-001',
                'documentId' => 'php-snippets',
                'position' => 0,
                'content' => 'Function calculateInvoiceTotal(array $items, float $taxRate): float sums line totals and applies tax.',
            ],
            [
                'id' => 'code-002',
                'documentId' => 'php-snippets',
                'position' => 1,
                'content' => 'Class RefundDecisionService uses evaluateWindow(DateTimeImmutable $paidAt) to enforce 30-day window.',
            ],
            [
                'id' => 'code-003',
                'documentId' => 'php-snippets',
                'position' => 2,
                'content' => 'Example code: if ($daysSincePayment <= 30) { approveRefund($orderId); } else { denyRefund($orderId); }',
            ],
        ];

        $embedded = [];
        foreach ($rows as $row) {
            $chunk = new Chunk(
                id: $row['id'],
                documentId: $row['documentId'],
                tenantId: $tenant,
                content: $row['content'],
                position: $row['position'],
                metadata: ['source' => 'demo', 'domain' => 'support'],
                collection: $collection,
                status: 'active',
            );
            $embedded[] = new EmbeddedChunk($chunk, $embeddings->embed($chunk->content, $tenant));
        }

        $store->storeBatch($embedded);
        $cached = [
            'tenant' => $tenant,
            'collection' => $collection,
            'embeddings' => $embeddings,
            'store' => $store,
        ];

        return $cached;
    }

    function demo_print_banner(string $title, string $question): void
    {
        echo PHP_EOL;
        echo '============================================================' . PHP_EOL;
        echo $title . PHP_EOL;
        echo 'Question: ' . $question . PHP_EOL;
        echo '============================================================' . PHP_EOL;
    }

    function demo_print_stage(int $index, string $title): void
    {
        echo PHP_EOL . '[' . $index . '] ' . $title . PHP_EOL;
    }

    function demo_unique_document_count(array $results): int
    {
        $ids = [];
        foreach ($results as $result) {
            if ($result instanceof VectorSearchResult) {
                $ids[$result->chunk->documentId] = true;
            }
        }

        return count($ids);
    }

    function demo_print_results(array $results, int $max = 5): void
    {
        if ($results === []) {
            echo 'No results.' . PHP_EOL;
            return;
        }

        foreach (array_slice($results, 0, $max) as $offset => $result) {
            if (!$result instanceof VectorSearchResult) {
                continue;
            }
            $rank = $offset + 1;
            $score = $result->fusionScore !== null
                ? ' fusion=' . number_format($result->fusionScore, 5, '.', '')
                : '';
            $neighbor = $result->neighbor ? ' neighbor=true' : '';
            echo '#'. $rank
                . ' chunk=' . $result->chunk->id
                . ' doc=' . $result->chunk->documentId
                . ' pos=' . $result->chunk->position
                . ' distance=' . number_format($result->distance, 5, '.', '')
                . $score
                . $neighbor
                . PHP_EOL;
            echo '  ' . $result->chunk->content . PHP_EOL;
        }
    }

    function demo_print_timings(RetrievalDiagnostics $diagnostics): void
    {
        echo PHP_EOL . 'Timing summary:' . PHP_EOL;
        foreach ($diagnostics->timingsMilliseconds as $stage => $ms) {
            echo '  - ' . $stage . ': ' . number_format($ms, 2, '.', '') . ' ms' . PHP_EOL;
        }
    }

    function demo_print_footer(int $startedNs, int $documents, int $chunks): void
    {
        $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;
        echo PHP_EOL;
        echo 'Execution time: ' . number_format($elapsedMs, 2, '.', '') . ' ms' . PHP_EOL;
        echo 'Documents found: ' . $documents . PHP_EOL;
        echo 'Chunks selected: ' . $chunks . PHP_EOL;
    }
}

final class DemoEmbeddingProvider implements EmbeddingProvider
{
    private EmbeddingSpace $space;

    public function __construct()
    {
        $this->space = new EmbeddingSpace('demo', 'token-hash-32', 32, '1');
    }

    public function space(): EmbeddingSpace
    {
        return $this->space;
    }

    public function embed(string $text, string $tenantId): Embedding
    {
        return new Embedding($this->vectorize($text), $this->space);
    }

    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        return array_map(fn (string $text): Embedding => new Embedding($this->vectorize($text), $this->space), $request->texts);
    }

    /** @return list<float> */
    private function vectorize(string $text): array
    {
        $vector = array_fill(0, $this->space->dimensions, 0.0);
        $tokens = $this->tokens($text);

        foreach ($tokens as $token) {
            $hash = crc32($token);
            $index = $hash % $this->space->dimensions;
            $sign = (($hash >> 1) & 1) === 0 ? 1.0 : -1.0;
            $weight = str_contains($token, '_') || str_contains($token, '-') ? 1.5 : 1.0;
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        $norm = sqrt($norm);
        if ($norm > 0) {
            foreach ($vector as $i => $value) {
                $vector[$i] = $value / $norm;
            }
        }

        return $vector;
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[\pL\pN_-]+/u', $normalized, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }
}

final class DemoInMemoryStore implements NeighborAwareVectorStore, LexicalSearchStore
{
    /** @var array<string, true> */
    private const LEXICAL_STOP_WORDS = [
        'a' => true, 'as' => true, 'como' => true, 'da' => true, 'das' => true,
        'de' => true, 'do' => true, 'dos' => true, 'e' => true, 'em' => true,
        'explique' => true, 'funciona' => true, 'o' => true, 'os' => true,
        'para' => true, 'por' => true, 'qual' => true, 'que' => true, 'uma' => true, 'é' => true,
        'algoritmo' => true, 'classe' => true, 'complexidade' => true,
    ];
    /** @var list<EmbeddedChunk> */
    private array $chunks = [];

    public function storeBatch(array $chunks): void
    {
        foreach ($chunks as $chunk) {
            if (!$chunk instanceof EmbeddedChunk) {
                continue;
            }
            $this->chunks[] = $chunk;
        }
    }

    public function search(VectorSearchQuery $query): array
    {
        $results = [];
        foreach ($this->chunks as $embedded) {
            $chunk = $embedded->chunk;
            if ($chunk->tenantId !== $query->tenantId) {
                continue;
            }
            if ($query->collection !== null && $chunk->collection !== $query->collection) {
                continue;
            }
            if ($chunk->status !== $query->status) {
                continue;
            }

            $distance = $this->distance(
                $query->embedding->values,
                $embedded->embedding->values,
                $query->policy->metric,
            );
            if ($query->policy->maximumDistance !== null && $distance > $query->policy->maximumDistance) {
                continue;
            }

            $results[] = new VectorSearchResult($chunk, $distance, 'v1');
        }

        usort(
            $results,
            static fn (VectorSearchResult $left, VectorSearchResult $right): int =>
                $left->distance <=> $right->distance
                ?: $left->chunk->id <=> $right->chunk->id,
        );

        return array_slice($results, 0, $query->policy->limit);
    }

    public function searchLexical(LexicalSearchQuery $query): array
    {
        $scored = [];
        $queryTokens = $this->lexicalTokens($query->terms);
        if ($queryTokens === []) {
            return [];
        }
        $queryTokenCount = count($queryTokens);

        foreach ($this->chunks as $embedded) {
            $chunk = $embedded->chunk;
            if ($chunk->tenantId !== $query->tenantId) {
                continue;
            }
            if ($query->collection !== null && $chunk->collection !== $query->collection) {
                continue;
            }
            if ($chunk->status !== $query->status) {
                continue;
            }

            $contentTokens = $this->lexicalTokens($chunk->content);
            $contentLookup = array_fill_keys($contentTokens, true);

            $hits = 0.0;
            foreach ($queryTokens as $token) {
                if (isset($contentLookup[$token])) {
                    $hits += str_contains($token, '_') || str_contains($token, '-') ? 1.4 : 1.0;
                }
            }

            if (str_contains(mb_strtolower($chunk->content), mb_strtolower($query->terms))) {
                $hits += 1.0;
            }
            if ($hits <= 0.0) {
                continue;
            }

            $score = $hits / $queryTokenCount;
            $distance = 1.0 / (1.0 + $score);
            if ($query->policy->maximumDistance !== null && $distance > $query->policy->maximumDistance) {
                continue;
            }

            $scored[] = [
                'score' => $score,
                'result' => new VectorSearchResult($chunk, $distance, 'v1', lexicalScore: $score),
            ];
        }

        usort(
            $scored,
            static fn (array $left, array $right): int =>
                $right['score'] <=> $left['score']
                ?: $left['result']->chunk->id <=> $right['result']->chunk->id,
        );

        return array_map(
            static fn (array $entry): VectorSearchResult => $entry['result'],
            array_slice($scored, 0, $query->policy->limit),
        );
    }

    public function neighbors(NeighborSearchQuery $query): array
    {
        $neighbors = [];
        $min = max(0, $query->position - $query->before);
        $max = $query->position + $query->after;

        foreach ($this->chunks as $embedded) {
            $chunk = $embedded->chunk;
            if ($chunk->tenantId !== $query->tenantId
                || $chunk->collection !== $query->collection
                || $chunk->status !== $query->status
                || $chunk->documentId !== $query->documentId
            ) {
                continue;
            }
            if ($chunk->position < $min || $chunk->position > $max || $chunk->position === $query->position) {
                continue;
            }
            $neighbors[] = $chunk;
        }

        usort(
            $neighbors,
            static fn (Chunk $left, Chunk $right): int => $left->position <=> $right->position,
        );

        return $neighbors;
    }

    /** @return list<string> */
    private function lexicalTokens(string $text): array
    {
        return array_values(array_filter(
            $this->tokens($text),
            static fn (string $token): bool => !isset(self::LEXICAL_STOP_WORDS[$token]),
        ));
    }

    public function deleteChunk(ChunkDeleteQuery $query): int
    {
        return 0;
    }

    public function deleteDocument(DocumentDeleteQuery $query): int
    {
        return 0;
    }

    public function clearCollection(CollectionDeleteQuery $query): int
    {
        return 0;
    }

    /** @param list<float> $left @param list<float> $right */
    private function distance(array $left, array $right, VectorMetric $metric): float
    {
        return match ($metric) {
            VectorMetric::COSINE => $this->cosineDistance($left, $right),
            VectorMetric::L2 => $this->l2Distance($left, $right),
            VectorMetric::L1 => $this->l1Distance($left, $right),
            VectorMetric::INNER_PRODUCT => max(0.0, 1.0 - $this->dot($left, $right)),
        };
    }

    /** @param list<float> $left @param list<float> $right */
    private function cosineDistance(array $left, array $right): float
    {
        $dot = $this->dot($left, $right);
        $normLeft = sqrt($this->dot($left, $left));
        $normRight = sqrt($this->dot($right, $right));

        if ($normLeft === 0.0 || $normRight === 0.0) {
            return 1.0;
        }

        $similarity = $dot / ($normLeft * $normRight);
        $similarity = max(-1.0, min(1.0, $similarity));

        return 1.0 - $similarity;
    }

    /** @param list<float> $left @param list<float> $right */
    private function l2Distance(array $left, array $right): float
    {
        $sum = 0.0;
        foreach ($left as $i => $value) {
            $diff = $value - $right[$i];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }

    /** @param list<float> $left @param list<float> $right */
    private function l1Distance(array $left, array $right): float
    {
        $sum = 0.0;
        foreach ($left as $i => $value) {
            $sum += abs($value - $right[$i]);
        }

        return $sum;
    }

    /** @param list<float> $left @param list<float> $right */
    private function dot(array $left, array $right): float
    {
        $sum = 0.0;
        foreach ($left as $i => $value) {
            $sum += $value * $right[$i];
        }

        return $sum;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[\pL\pN_-]+/u', $normalized, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }
}

final class DemoLanguageModel implements LanguageModel, StreamingLanguageModel
{
    public function complete(array $messages): string
    {
        $user = '';
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage && $message->role->value === 'user') {
                $user = $message->content;
            }
        }

        return 'Buffered answer generated from contextual evidence. User prompt size=' . strlen($user) . ' bytes.';
    }

    public function stream(array $messages): iterable
    {
        $parts = [
            'Streaming answer ',
            'generated chunk by chunk ',
            'from contextual evidence.',
        ];

        $sequence = 0;
        foreach ($parts as $part) {
            yield new AnswerDelta($part, $sequence, false);
            ++$sequence;
        }

        yield new AnswerDelta('', $sequence, true);
    }
}
