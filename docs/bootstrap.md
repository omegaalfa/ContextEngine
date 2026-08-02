# ⚡ Bootstrap tipado

O Bootstrap padrão reduz a composição repetitiva de uma aplicação baseada em **Ollama + PostgreSQL/pgvector**. Ele constrói diretamente o grafo padrão e retorna `ContextEngineContext`, cuja superfície pública é conhecida pelo PHPStan e pelas IDEs. Não há container nem acesso por Service Locator.

```text
ContextEngineConfig
        ↓
Bootstrap::create()
        ↓
composição direta e tipada
        ↓
ContextEngineContext
   ├── retriever
   ├── ingestion
   ├── rag
   ├── embeddings
   └── store
```

## Uso mínimo

```php
use Omegaalfa\ContextEngine\Bootstrap\Bootstrap;
use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaLanguageModel;
use Omegaalfa\ContextEngine\Rag\Question;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\Utils\EnvLoader\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$context = Bootstrap::create(
    config: ContextEngineConfigFactory::fromEnvironment(),
    languageModelFactory: static fn (AsyncHttpClient $http) => new OllamaLanguageModel(
        model: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_LANGUAGE_MODEL') ?? 'qwen3:8b',
        client: $http
            ->readTimeout(300)
            ->totalTimeout(300),
        baseUrl: EnvLoader::get('CONTEXT_ENGINE_OLLAMA_URL') ?? 'http://127.0.0.1:11434',
    ),
);

$results = $context->retriever->retrieve(
    new Question('Qual é o prazo?', 'tenant-42'),
);

$answer = $context->rag->ask(
    new Question('Qual é o prazo?', 'tenant-42'),
);
```

O `LanguageModel` é criado por uma factory porque ela recebe o mesmo `AsyncHttpClient` compartilhado com o provider de embeddings. Assim, o Bootstrap cria exatamente um `FiberEventLoop`; o loop permanece um detalhe de infraestrutura e não aparece em `ContextEngineContext`.

## Configuração

`ContextEngineConfigFactory::fromEnvironment()` lê um ambiente que já foi preenchido pelo processo, Docker, CI, secret manager ou `EnvLoader`. A factory não procura nem carrega `.env` por conta própria.

As opções incluem conexão pgvector, modelo Ollama, collection, status, batching, concorrência, chunking, métrica, limite e distância máxima. Tenant não pertence à configuração global: ele deve vir de cada `Question` ou `Document`, normalmente depois da autenticação da requisição.

## Limites intencionais

- A composição padrão escolhe Ollama para embeddings e pgvector para persistência.
- O LLM permanece intercambiável pelo contrato `LanguageModel`; OpenAI, Gemini ou um adapter próprio podem ser usados.
- O contexto não expõe o event loop nem a configuração com senha.
- O Bootstrap não instala nem esconde um container: as dependências são construídas explicitamente no composition root.
- Aplicações que precisam de outra infraestrutura podem compor os contratos diretamente, com ou sem o container escolhido pela própria aplicação.

Veja o exemplo executável em `examples/simple-rag.php`.
