# 🧪 Avaliação reproduzível de RAG

O módulo `Evaluation` mede a qualidade do pipeline real do ContextEngine de forma **determinística**, **opcional** e **sem depender de outro modelo de IA**.

Ele é útil para comparar versões de parser, chunking, embeddings, busca híbrida, reranking ou políticas de retrieval sem criar um pipeline paralelo.

> O avaliador chama `RagPipeline::askWithDiagnostics()` e reutiliza `RagExecution`, `RagDiagnostics` e `RetrievalDiagnostics`.

---

## 🎯 O que é medido

| Métrica | Interpretação |
|---|---|
| **Recall** | Fração dos chunks ou documentos esperados que foi recuperada. |
| **Precision** | Fração dos itens recuperados que pertence ao conjunto esperado. |
| **MRR** | Inverso da posição do primeiro resultado relevante. Quanto mais perto de `1`, melhor. |
| **Hit Rate** | Vale `1` quando ao menos um resultado esperado foi encontrado. |
| **Exact Match** | Compara resposta real e esperada após normalizar caixa, pontuação e espaços. |
| **Contains Expected Terms** | Fração dos termos obrigatórios encontrados na resposta. |

As métricas só são calculadas quando o caso fornece a expectativa correspondente. Um caso sem nenhuma expectativa é executado, mas não é considerado aprovado.

## 📦 Criando um dataset em PHP

```php
use Omegaalfa\ContextEngine\Evaluation\EvaluationCase;
use Omegaalfa\ContextEngine\Evaluation\EvaluationDataset;

$dataset = new EvaluationDataset([
    new EvaluationCase(
        id: 'dijkstra',
        question: 'Como funciona o algoritmo de Dijkstra?',
        expectedAnswer: 'Dijkstra encontra caminhos mínimos...',
        relevantChunkIds: ['algorithms:42'],
        relevantDocumentIds: ['algorithms-book'],
        expectedTerms: ['caminho mínimo', 'pesos não negativos'],
        metadata: ['category' => 'graphs'],
    ),
], name: 'Algorithms');
```

Você pode informar chunks esperados, documentos esperados, resposta esperada ou termos obrigatórios. Nem todos são necessários.

Quando há `relevantChunkIds`, as métricas de retrieval usam chunks. Caso contrário, usam `relevantDocumentIds`.

## ▶️ Executando

```php
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;

$evaluator = new RagEvaluator(tenantId: 'acme');
$report = $evaluator->evaluate(
    pipeline: $rag,
    dataset: $dataset,
);

echo $report->passedCases.'/'.$report->executedCases;
echo $report->averageRecall;
echo $report->meanReciprocalRank;
```

Se cada caso já contém um objeto `Question`, o tenant do construtor é opcional. Também é possível definir `tenantId` individualmente no `EvaluationCase`.

## 🗂️ Dataset JSON

```json
[
  {
    "id": "dijkstra",
    "question": "Como funciona Dijkstra?",
    "expectedAnswer": "Dijkstra encontra caminhos mínimos...",
    "expectedTerms": ["caminho mínimo"],
    "relevantChunkIds": ["algorithms:42"],
    "relevantDocumentIds": ["algorithms-book"],
    "metadata": {"category": "graphs"}
  }
]
```

```php
use Omegaalfa\ContextEngine\Evaluation\EvaluationDatasetLoader;

$loader = new EvaluationDatasetLoader();
$dataset = $loader->fromFile('evaluation.json', 'Algorithms');
```

O loader valida tipos, IDs e listas antes da avaliação. JSON inválido ou campos incompatíveis geram exceção imediatamente.

## 📊 Relatório

`EvaluationReport` contém:

- casos executados e aprovados;
- médias de recall, precision, MRR e hit rate;
- tempo total, tempo médio e latência média informada pelo RAG;
- quantidades de chunks fundidos e selecionados;
- `EvaluationResult` individual de cada caso;
- `RagExecution` original para análise detalhada;
- erro capturado, quando um caso falha durante a execução.

Uma falha individual não interrompe o restante do dataset.

## 🧩 Adicionando uma métrica

Implemente `CaseEvaluator` e injete a lista no construtor:

```php
$evaluator = new RagEvaluator(
    tenantId: 'acme',
    evaluators: [
        new RetrievalRecallEvaluator(),
        new MinhaMetricaDeterministica(),
    ],
);
```

Cada avaliador recebe o `EvaluationCase` e o `RagExecution` produzido pelo pipeline real. Isso permite adicionar futuramente groundedness, relevância ou correctness sem alterar os modelos públicos atuais.

## 💻 Exemplo de terminal

```bash
php examples/evaluation/evaluate.php
```

O exemplo usa infraestrutura local em memória, não exige banco, Ollama ou credenciais externas.

## ⚠️ Limitações

- Exact Match é propositalmente rígido, apesar da normalização textual.
- Termos esperados verificam presença textual, não equivalência semântica.
- Recall exige que o dataset conheça IDs estáveis de chunks ou documentos.
- As médias ignoram métricas não aplicáveis, em vez de tratá-las como zero.
- Avaliação por LLM não faz parte desta implementação inicial.

