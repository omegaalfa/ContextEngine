# Providers incluídos

## OpenAIEmbeddingProvider

Namespace: `Omegaalfa\ContextEngine\Provider\OpenAI`. Construtor:

```text
__construct(
    string $apiKey,
    string $model = 'text-embedding-3-small',
    int $dimensions = 1536,
    AsyncHttpClient $client = new AsyncHttpClient(),
    string $baseUrl = 'https://api.openai.com/v1'
)
```

`embed()` e `embedBatch()` fazem POST em `/embeddings`, com Bearer token, JSON, model, input e dimensions. Um request representa um lote; concorrência global fica fora do provider. Resposta com cardinalidade diferente, item ausente ou espaço esperado incompatível lança `ProviderException`/erro de validação.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAIEmbeddingProvider;

$provider = new OpenAIEmbeddingProvider(
    apiKey: (string) getenv('OPENAI_API_KEY'),
    model: 'text-embedding-3-small',
    dimensions: 1536,
);
```

## OllamaEmbeddingProvider

Construtor: `__construct(string $model, int $dimensions, AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'http://127.0.0.1:11434')`. Usa `/api/embed`, sem autenticação embutida. O modelo precisa existir localmente e a dimensão informada deve coincidir com a resposta.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;

$provider = new OllamaEmbeddingProvider(
    model: 'nomic-embed-text',
    dimensions: 768,
);
```

## OpenAILanguageModel

Construtor: `__construct(string $apiKey, string $model = 'gpt-4.1-mini', AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'https://api.openai.com/v1')`. `complete(list<ChatMessage>): string` chama `/chat/completions`. `generationFingerprint()` identifica provider/model/parâmetros padrões atuais.

O corpo HTTP é buffered. Esta classe implementa `CacheableLanguageModel`, não `StreamingLanguageModel`.
