# Concorrência e backpressure

`BatchEmbeddingExecutor::execute(iterable $batches, EmbeddingProvider $provider): iterable<BatchEmbeddingResult>` é o contrato. A implementação real é `Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor`.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

$eventLoop = new FiberEventLoop();
$httpClient = new AsyncHttpClient($eventLoop);
$batchExecutor = new FiberBatchEmbeddingExecutor(
    loop: $eventLoop,
    concurrency: 5,
);
```

O provider deve receber `$httpClient`, e a pipeline deve receber `$batchExecutor`. Essa identidade de instância é obrigatória: dois loops independentes não coordenam os mesmos fibers e podem fazer `ingest()` permanecer aguardando indefinidamente.

```text
FiberEventLoop compartilhado
├── AsyncHttpClient → EmbeddingProvider
└── FiberBatchEmbeddingExecutor → IngestionPipeline
```

O argumento real chama-se `concurrency`, não `window`. Deve ser positivo. O executor inicia no máximo essa quantidade de lotes, cada um com sequence própria. Mesmo quando operações terminam fora de ordem, os resultados são entregues associados à sequence/chunks corretos e, na implementação atual, consumidos na ordem da janela.

`Future` continua totalmente interno. O `FiberEventLoop` é visto apenas pelo composition root e pela infraestrutura concreta; contratos, domínio, providers públicos e `RagPipeline` retornam tipos síncronos/iterables.

Após falha, a janela é drenada e resultados posteriores descartados. O executor não conhece banco; o pipeline persiste cada resultado serialmente.

Trade-off importante de custo:

- se um lote falha em persistência, lotes da mesma janela que já iniciaram chamadas HTTP continuam até concluir;
- esses tokens/requests ainda podem ser cobrados pelo provider, mesmo quando o resultado final da janela será descartado;
- aumentar `concurrency` reduz latência média em cenário saudável, mas aumenta custo potencial sob falha parcial.

Escolha `concurrency` considerando esse equilíbrio custo x throughput para sua carga real.

- Janela 1: menor pressão e memória, menor paralelismo.
- Janela 5: equilíbrio inicial razoável, sujeito ao provider.
- Janela 20: maior memória, conexões e risco de rate limit.

Meça no ambiente real; o projeto ainda não publica benchmarks.
