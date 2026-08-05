# 🧩 Extensão da biblioteca

Implemente contratos, preserve invariantes e injete a nova implementação. Não altere o domínio para cada provider.

## Loader customizado

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Document\Document;

final readonly class MemoryLoader implements DocumentLoader
{
    /** @param list<string> $texts */
    public function __construct(private array $texts, private string $tenantId) {}

    public function load(): iterable
    {
        foreach ($this->texts as $index => $text) {
            yield new Document("memory-$index", $this->tenantId, $text);
        }
    }
}
```

## EmbeddingProvider customizado

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Embedding\{Embedding,EmbeddingBatchRequest,EmbeddingSpace};

final readonly class LocalEmbeddingProvider implements EmbeddingProvider
{
    public function space(): EmbeddingSpace
    {
        return new EmbeddingSpace('local', 'example', 2, '1');
    }

    public function embed(string $text, string $tenantId): Embedding
    {
        return $this->embedBatch(
            new EmbeddingBatchRequest($tenantId, [$text], $this->space()),
        )[0];
    }

    public function embedBatch(EmbeddingBatchRequest $request): array
    {
        if ($request->expectedSpace->fingerprint() !== $this->space()->fingerprint()) {
            throw new LogicException('Espaço incompatível.');
        }

        return array_map(
            fn (string $text): Embedding => new Embedding(
                [(float) strlen($text), (float) substr_count($text, ' ')],
                $this->space(),
            ),
            $request->texts,
        );
    }
}
```

Garanta cardinalidade, ordem, dimensão e espaço. O provider não controla concorrência global.

## Gemini ou outro fornecedor

`GeminiLanguageModel` já está incluído para respostas completas buffered. Para embeddings Gemini, Anthropic, gateways corporativos ou qualquer outro fornecedor, uma integração externa deve implementar `EmbeddingProvider`, `LanguageModel` e/ou `StreamingLanguageModel`, apenas conforme as capacidades reais do transporte.

O adapter fica responsável por:

- validar credencial, endpoint, modelo e dimensão;
- traduzir request/response sem expor o SDK no contrato;
- declarar `EmbeddingSpace` com parâmetros semânticos;
- preservar cardinalidade e ordem em batches;
- converter falhas externas em diagnóstico consistente;
- implementar streaming somente se receber dados incrementalmente.

Depois disso, injete a implementação nas pipelines exatamente como os providers incluídos. Nenhuma alteração em `IngestionPipeline`, `Retriever` ou `RagPipeline` é necessária.

## LanguageModel e streaming

`LanguageModel::complete()` retorna uma resposta completa. `StreamingLanguageModel::stream()` é independente e só deve ser implementado com transporte incremental. Não reutilize completion buffered para criar deltas.

## Splitter, store e executor

- `TextSplitter` deve propagar tenant/collection/status e produzir chunks válidos.
- `VectorStore` deve filtrar tenant e espaço antes de limitar resultados, persistir cada batch atomicamente e aplicar tenant em todas as exclusões. Filtros opcionais de `DocumentDeleteQuery` só entram no comando quando informados; `deleteChunk()` não pode ignorar collection nem identidade vetorial completa.
- `BatchEmbeddingExecutor` associa sequence, chunks e resultado, drena recursos em falha e não persiste.
- Um decorator de cache deve implementar o mesmo contrato, delegar misses e nunca armazenar falhas/parciais.

Novas políticas podem ser instâncias de `RetrievalPolicy`; novas formas de filtro exigiriam evolução explícita do contrato, pois metadata arbitrária não faz parte da API atual.
