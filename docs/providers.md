# Providers incluídos

| Capacidade | Implementação incluída | Observação |
|---|---|---|
| Embeddings OpenAI | `OpenAIEmbeddingProvider` | Buffered HTTP; lote por request. |
| Embeddings Ollama | `OllamaEmbeddingProvider` | Modelo e dimensão vêm da aplicação. |
| Resposta OpenAI | `OpenAILanguageModel` | `complete()` buffered e `stream()` incremental via SSE real. |
| Resposta Ollama | `OllamaLanguageModel` | Chat local buffered com `stream: false`. |
| Resposta Gemini | `GeminiLanguageModel` | `generateContent` buffered, sem streaming incremental. |

Pipelines dependem de `EmbeddingProvider`, `LanguageModel` e, quando real, `StreamingLanguageModel`. Portanto, adicionar fornecedor não exige alterar ingestão ou RAG, mas exige um adapter que preserve os contratos.

## OpenAIEmbeddingProvider

O adapter valida a configuração antes da primeira chamada: API key e modelo não podem ser vazios, a dimensão deve ser positiva e o endpoint deve ser uma URL HTTP(S) absoluta sem credenciais, query ou fragmento. A barra final do endpoint é normalizada.

Na resposta, a quantidade de vetores deve coincidir exatamente com a quantidade de textos. O campo `index` da OpenAI é usado para reconstruir a ordem original, inclusive quando a API devolver os itens fora de ordem. Índices ausentes ou duplicados, dimensões incompatíveis, valores não numéricos, `NaN` e infinito são rejeitados como `ProviderException`.

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

O adapter Ollama aplica as mesmas garantias de modelo, dimensão, endpoint, cardinalidade e validade numérica. Como `/api/embed` define os embeddings na ordem dos inputs e não fornece índices individuais, essa ordem posicional é preservada e validada.

As mensagens públicas de falhas HTTP não reproduzem o corpo retornado pelo serviço. A exceção técnica original permanece disponível em `getPrevious()` para logging controlado; ela não deve ser exibida diretamente ao usuário final.

Construtor: `__construct(string $model, int $dimensions, AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'http://127.0.0.1:11434')`. Usa `/api/embed`, sem autenticação embutida. O modelo precisa existir localmente e a dimensão informada deve coincidir com a resposta.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;

$modelName = (string) getenv('OLLAMA_EMBEDDING_MODEL');
$modelDimensions = (int) getenv('OLLAMA_EMBEDDING_DIMENSIONS');

$provider = new OllamaEmbeddingProvider(
    model: $modelName,
    dimensions: $modelDimensions,
);
```

Os valores não possuem padrão no código. Valide que não estão vazios/zerados e que correspondem ao modelo realmente instalado antes de construir o provider.

## OpenAILanguageModel

Construtor: `__construct(string $apiKey, string $model = 'gpt-4.1-mini', AsyncHttpClient $client = new AsyncHttpClient(), string $baseUrl = 'https://api.openai.com/v1')`. `complete(list<ChatMessage>): string` chama `/chat/completions` em modo buffered. `stream(list<ChatMessage>): iterable<AnswerDelta>` usa SSE incremental real no mesmo endpoint com `stream: true`. `generationFingerprint()` identifica provider/model/parâmetros padrões atuais.

Esta classe implementa `CacheableLanguageModel` e `StreamingLanguageModel`.

## OllamaLanguageModel

`OllamaLanguageModel` chama `POST /api/chat` com `stream: false`, valida que a resposta terminou e exige `message.content` não vazio. O cliente HTTP é obrigatório no construtor para que o bootstrap compartilhe explicitamente um único `FiberEventLoop`.

```php
$model = new OllamaLanguageModel(
    model: 'qwen3:8b',
    client: $http->readTimeout(300)->totalTimeout(300),
    baseUrl: 'http://127.0.0.1:11434',
    options: ['temperature' => 0.2],
);
```

As opções são ordenadas e participam de `generationFingerprint()`. `keepAlive` controla somente a permanência do modelo no Ollama. A classe implementa `CacheableLanguageModel`, não `StreamingLanguageModel`; nenhuma resposta materializada é dividida em deltas.

## GeminiLanguageModel

`GeminiLanguageModel` chama o endpoint REST `models/{model}:generateContent` e envia a API key no header `x-goog-api-key`. Mensagens `system` são reunidas em `systemInstruction`, mensagens `user` permanecem `user` e mensagens `assistant` são convertidas para o papel `model` usado pelo Gemini.

```php
use Omegaalfa\ContextEngine\Provider\Gemini\GeminiLanguageModel;

$model = new GeminiLanguageModel(
    apiKey: (string) getenv('GEMINI_API_KEY'),
    model: 'gemini-3.6-flash',
    client: $http,
    generationConfig: [
        'maxOutputTokens' => 1_024,
    ],
);
```

O adapter concatena as partes textuais visíveis da primeira candidata e ignora partes marcadas como pensamento. Bloqueios do prompt e respostas sem texto útil geram `ProviderException` sem reproduzir o corpo remoto. A configuração de geração é validada, ordenada recursivamente e participa do fingerprint de cache. O endpoint é buffered; a classe implementa `CacheableLanguageModel`, não `StreamingLanguageModel`.
