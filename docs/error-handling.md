# Tratamento de erros

| Exceção | Quando ocorre | Recuperação |
|---|---|---|
| `ContextEngineException` | base pública dos erros próprios | depende da subclasse |
| `InvalidEmbeddingException` | vetor vazio, valor não finito, dimensão ou batch incompatível | corrigir espaço/provider/dados |
| `ProviderException` | HTTP falha, JSON/estrutura/cardinalidade inválida, espaço solicitado incompatível | retry externo ou corrigir provider |
| `IngestionException` | falha de executor, provider, validação ou store | usar relatório e reexecutar idempotentemente |
| `StreamingNotSupportedException` | `stream()` sem provider incremental | configurar capacidade real ou usar `ask()` |

Também podem propagar `InvalidArgumentException` dos value objects/políticas, exceções do QueryBuilder na persistência/retrieval e exceções do HttpClient como causa de `ProviderException` ou erro de transporte.

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Exception\IngestionException;

try {
    $report = $pipeline->ingest($loader);
} catch (IngestionException $error) {
    $partial = $error->partialReport;
    error_log($error->getPrevious()?->getMessage() ?? $error->getMessage());
    error_log('Persistidos: '.$partial->chunksPersisted);
    error_log('Lote que falhou: '.$error->failedBatchSequence);
}
```

Configuração inválida tende a falhar na construção. Rate limit, timeout e conexão são potencialmente recuperáveis; dimensão/modelo/schema incorretos exigem correção, não retry cego.
