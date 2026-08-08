# 🏁 Reranking opcional

Reranking é uma segunda opinião aplicada **depois que a busca já encontrou candidatos**.

```text
vetorial + lexical
        ↓
       RRF
        ↓
  até 20 candidatos
        ↓
     Reranker
        ↓
  top 5 reordenados
        ↓
 ContextSelector → LLM
```

## 🤔 Qual problema ele resolve?

A busca pode encontrar o chunk correto, mas deixá-lo na posição 10. Se somente cinco chunks forem enviados ao modelo, esse conteúdo desaparece no corte final.

O reranker não procura documentos novos. Ele recebe os candidatos do RRF e tenta colocar os mais úteis no começo.

> Se o chunk correto não aparece nem entre os 20 ou 50 candidatos, reranking não resolve. Nesse caso, investigue embeddings, busca lexical, query rewriting, corpus ou golden.

## 🔌 Contrato

```php
use Omegaalfa\ContextEngine\Contract\Reranker;

interface Reranker
{
    public function rerank(Question $question, array $results): array;
}
```

O reranker deve devolver **os mesmos candidatos, exatamente uma vez**, apenas em outra ordem. O `Retriever` rejeita implementações que removem, duplicam ou inventam chunks.

## ⚙️ Configuração

O recurso é opcional. Sem `reranker`, o comportamento permanece igual.

```php
use Omegaalfa\ContextEngine\Retrieval\DeterministicTextualReranker;
use Omegaalfa\ContextEngine\Retrieval\Retriever;

$retriever = new Retriever(
    embeddings: $embeddings,
    store: $store,
    policy: $policy,
    fusedLimit: 20,
    contextChunkLimit: 5,
    lexicalStore: $store,
    reranker: new DeterministicTextualReranker(),
    rerankerCandidateLimit: 5,
);
```

Na High-Level API, use `withCustomComponents()` quando precisar injetar um reranker próprio. O fluxo padrão não habilita reranking silenciosamente.

## 🧪 Implementação determinística incluída

`DeterministicTextualReranker` mede cobertura dos termos significativos da pergunta no conteúdo do chunk.

Ele é útil para:

- testar a integração sem serviço externo;
- executar benchmarks offline reproduzíveis;
- demonstrar o contrato;
- criar uma baseline antes de adotar cross-encoder.

Ele **não é um reranker semântico forte**. Para cross-encoder remoto, o pacote inclui `CohereReranker`; outras implementações podem usar o mesmo contrato sem alterar o pipeline.

## 🔍 Diagnóstico completo

O reranking nunca sobrescreve `distance`. Cada resultado pode preservar:

| Campo | Significado |
|---|---|
| `distance` | distância vetorial original |
| `lexicalScore` | score fornecido pela busca lexical |
| `fusionScore` | score calculado pelo RRF |
| `rerankerScore` | score produzido pelo reranker |

`RetrievalDiagnostics::reranking` fornece um `RerankDiagnostic` por candidato:

```php
foreach ($outcome->diagnostics->reranking as $item) {
    echo $item->chunkId;
    echo $item->rankBefore.' → '.$item->rankAfter;
    echo $item->vectorDistance;
    echo $item->lexicalScore;
    echo $item->fusionScore;
    echo $item->rerankerScore;
}
```

O diagnóstico da execução também informa `rerankerName`, `rerankerCandidateCount`, `rerankerReturnedCount` e o tempo em `timingsMilliseconds['reranking']`. Assim, implementações diferentes podem ser comparadas por qualidade, custo e latência.

O tempo da etapa fica em:

```php
$outcome->diagnostics->timingsMilliseconds['reranking'];
```

## 📊 Benchmark antes e depois

Execute:

```bash
php examples/evaluation/compare-reranker.php
```

O exemplo usa o mesmo corpus, golden e configuração para os dois lados. Ele compara:

- Recall@5;
- Precision@5;
- MRR;
- Hit@1, que mede quantas perguntas colocaram um chunk esperado na primeira posição;
- Document Recall@5;
- Document MRR;
- latência média;
- posição antes e depois de cada chunk relevante.

Interprete o resultado em conjunto:

```text
recall e MRR sobem bastante + latência aceitável → reranker agrega valor
ganho pequeno + latência alta                    → talvez não compense
Recall@20 baixo antes do reranker                → problema está nos candidatos
```

## 🌐 Benchmark com infraestrutura real

Depois do teste offline, execute:

```bash
php examples/evaluation/compare-reranker-live.php
```

O exemplo usa embeddings configurados, PgVector e busca lexical PostgreSQL. O corpus é ingerido uma vez e dois retrievers idênticos comparam `sem reranker` e `DeterministicTextualReranker`.

O modelo de linguagem não é chamado. As métricas e a latência medem somente candidate generation, RRF, reranking e seleção.

Não presuma que o reranker textual sempre melhora o resultado. Em embeddings reais, ele pode manter Recall@5 e ainda piorar MRR ou Hit@1. Esse resultado significa que a extensão funciona, mas a heurística não deve ser habilitada naquele pipeline; use o benchmark para decidir.

## ☁️ Cross-encoder Cohere

`CohereReranker` implementa o mesmo contrato usando a API Rerank v2. Ele envia a pergunta e os textos candidatos para `POST /v2/rerank`, preserva os scores anteriores e grava `relevance_score` em `rerankerScore`.

```php
use Omegaalfa\ContextEngine\Provider\Cohere\CohereReranker;

$reranker = new CohereReranker(
    apiKey: getenv('COHERE_API_KEY'),
    model: 'rerank-v4.0-pro',
    timeoutSeconds: 10,
);
```

O modelo padrão é multilíngue. Para priorizar latência, a Cohere também oferece `rerank-v4.0-fast`. Consulte a [referência oficial da API Rerank v2](https://docs.cohere.com/v2/reference/rerank) e a [lista oficial de modelos](https://docs.cohere.com/v2/docs/rerank).

Para incluir Cohere no benchmark live:

```bash
export COHERE_API_KEY='...'
export COHERE_RERANK_MODEL='rerank-v4.0-pro'
php examples/evaluation/compare-reranker-live.php
```

Sem a chave, o cenário Cohere é ignorado e os cenários baseline/textual continuam executáveis.

### Fail-open

Falhas operacionais do reranker remoto não interrompem o RAG. O `Retriever` reutiliza a ordem do RRF e registra:

- `rerankerFailureCount`;
- `rerankerFallbackCount`;
- `rerankerTimedOut`;
- `rerankerError` sanitizado;
- `rerankerProvider` e `rerankerModel`.

Implementações que removem, duplicam ou inventam candidatos continuam lançando erro. Fail-open protege disponibilidade; não deve esconder bugs de contrato.

## ⚠️ Limitações

- reranking não recupera chunks ausentes;
- o reranker determinístico incluído usa texto, não semântica profunda;
- scores de modelos diferentes não devem ser comparados como se tivessem a mesma escala;
- um reranker remoto adicionará latência e pode falhar, portanto deve ter timeout e tratamento de erro no adapter;
- os sete casos de `evaluate-answer-quality.php` continuam sendo regressões fixas do evaluator, não um benchmark de ranking.
