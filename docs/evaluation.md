# 🧪 Avaliação reproduzível de RAG

O módulo `Evaluation` mede a qualidade do pipeline real do ContextEngine de forma **determinística**, **opcional** e **sem depender de outro modelo de IA**.

Ele é útil para comparar versões de parser, chunking, embeddings, busca híbrida, reranking ou políticas de retrieval sem criar um pipeline paralelo.

> O avaliador chama `RagPipeline::askWithDiagnostics()` e reutiliza `RagExecution`, `RagDiagnostics` e `RetrievalDiagnostics`.

---

## Evidência flexível

Quando vários chunks podem sustentar a mesma resposta, use `RelevantEvidence` em vez de exigir um único `chunkId`. A evidência combina o documento esperado com grupos de trechos equivalentes:

```php
new RelevantEvidence(
    documentId: $optimalBstDocument,
    requiredTextGroups: [['root'], ['raiz'], ['intervalo']],
);
```

O relatório live imprime a resposta, as consultas geradas, os grupos encontrados ou ausentes e os sinais de ranking de cada fonte. Isso evita confundir uma resposta equivalente com falha de retrieval.

## 🧭 Qual métrica usar?

Pense nas métricas como perguntas diferentes:

| Métrica | Pergunta respondida | Decide aprovação por padrão? |
|---|---|---|
| **Groundedness** | As afirmações da resposta possuem apoio nos chunks selecionados? | Sim |
| **Answer Relevance** | A resposta trata diretamente do que foi perguntado? | Sim |
| **Correctness** | A resposta contém os fatos definidos no gabarito? | Sim, quando existe gabarito |
| **Expected Terms** | Os termos configurados apareceram literalmente? | Não |
| **Exact Match** | A resposta é igual ao texto esperado? | Não |

`Expected Terms` e `Exact Match` continuam disponíveis para diagnóstico e regressão. Eles não reprovam sozinhos uma resposta correta, pois uma mesma ideia pode ser escrita de maneiras diferentes.

## 🧱 Groundedness determinístico

`DeterministicTextualGroundednessEvaluator` divide a resposta em afirmações e procura apoio textual nos chunks usados para gerar a resposta. `DeterministicGroundednessEvaluator` permanece como nome compatível para aplicações existentes.

Ele considera:

- palavras significativas, ignorando conectivos comuns;
- números, fórmulas e identificadores;
- frases já presentes no contexto;
- entidades citadas na resposta, penalizando entidades ausentes;
- o chunk que sustentou cada afirmação.

O detalhe fica disponível em `EvaluationScore::details` como `GroundednessResult`:

```php
$score = $result->scores['groundedness'];
$details = $score->details;

foreach ($details->supportedClaims as $claim) {
    echo $claim['claim'].' apoiada por '.$claim['chunkId'];
}

foreach ($details->unsupportedClaims as $claim) {
    echo 'Sem apoio: '.$claim;
}
```

> Esta primeira versão mede **apoio textual determinístico**. Ela não compreende equivalência semântica completa e não substitui revisão humana em domínios críticos.

Ela detecta divergências lexicais importantes, como negação inserida, fórmula alterada e identificador trocado. Ainda assim, não garante equivalência lógica nem ausência de contradições sofisticadas.

## 🎯 Relevância da resposta

`AnswerRelevanceEvaluator` compara a intenção e os termos centrais da pergunta com a resposta. O gabarito não interfere nessa métrica.

Se a pergunta for “Qual é a complexidade do Bellman-Ford?”, a resposta “O Bellman-Ford possui complexidade O(VE)” pode obter relevância máxima sem mencionar pesos negativos, pois isso não foi solicitado.

## ✅ Correctness com claims

Use `ExpectedClaim` quando houver um fato objetivo que a resposta precisa conter. Cada claim possui alternativas aceitas:

```php
use Omegaalfa\ContextEngine\Evaluation\ExpectedClaim;

new EvaluationCase(
    id: 'bellman-ford-complexity',
    question: 'Qual é a complexidade do Bellman-Ford?',
    expectedClaims: [
        new ExpectedClaim(
            id: 'complexity',
            alternatives: ['O(VE)', 'O(V * E)', 'O(|V||E|)'],
        ),
    ],
);
```

Sem `expectedClaims` ou `expectedAnswer`, `Correctness` fica **não aplicável**. Ausência de gabarito nunca vira nota zero.

> `Correctness = 1.00` significa **compatível com os claims configurados no golden**. Não significa “verdade factual universal”. Um claim mal definido pode aprovar uma resposta errada; por isso, configure a relação factual completa em cada alternativa, e não apenas palavras isoladas.

Evite isto:

```php
new ExpectedClaim('creator', ['Wesley', 'Bellman-Ford', '1999']);
```

Prefira alternativas que expressem o fato completo:

```php
new ExpectedClaim('creator', [
    'O contexto não informa quem criou o algoritmo Wesley.',
    'Não há evidência de um algoritmo chamado Wesley.',
]);
```

O dataset JSON também aceita claims:

```json
{
  "id": "bellman-ford-complexity",
  "question": "Qual é a complexidade do Bellman-Ford?",
  "expectedClaims": [
    {
      "id": "complexity",
      "alternatives": ["O(VE)", "O(V * E)", "O(|V||E|)"]
    }
  ]
}
```

## ⚙️ Política de aprovação

Os limites são configuráveis e independentes das métricas de diagnóstico:

```php
use Omegaalfa\ContextEngine\Evaluation\AnswerEvaluationPolicy;
use Omegaalfa\ContextEngine\Evaluation\RagEvaluator;

$evaluator = new RagEvaluator(
    tenantId: 'acme',
    policy: new AnswerEvaluationPolicy(
        minimumGroundedness: 0.80,
        minimumAnswerRelevance: 0.80,
        minimumCorrectness: 0.80,
        requireExpectedTerms: false,
    ),
);
```

Para regressões estritamente literais, defina `requireExpectedTerms: true`. Na maioria dos casos de RAG, mantenha `false`.

## 🔌 Sem dependência de provider

Os avaliadores recebem somente `EvaluationCase` e `RagExecution`. Eles não conhecem Ollama, OpenAI, Cohere, banco vetorial ou qualquer outro provider.

O comportamento padrão permanece:

```text
offline → determinístico → sem custo externo → reproduzível
```

Uma futura implementação baseada em LLM poderá implementar `AnswerEvaluator` e ser fornecida explicitamente, sem alterar o comportamento padrão.

## ▶️ Exemplo completo pela linha de comando

O exemplo abaixo não exige Ollama, OpenAI, PostgreSQL ou acesso à internet:

```bash
php examples/evaluation/evaluate-answer-quality.php
```

Ele executa o pipeline real da biblioteca e demonstra:

- uma resposta correta que não contém todos os termos diagnósticos;
- uma resposta sem gabarito factual, com `Correctness` como `N/A`;
- uma afirmação inventada, marcada como sem apoio;
- claims com várias escritas aceitas;
- política de aprovação independente de `Expected Terms`.

---

## 🎯 O que é medido

| Métrica | Interpretação |
|---|---|
| **Chunk Recall / Precision / MRR** | Mede se os trechos corretos foram recuperados e em qual posição. |
| **Document Recall / Precision / MRR** | Mede os documentos separadamente dos chunks. Um documento correto não torna qualquer chunk dele relevante. |
| **Hit Rate** | Vale `1` quando ao menos um resultado esperado foi encontrado. |
| **Hit@1** | Vale `1` quando o primeiro resultado é esperado; é especialmente útil para medir rerankers. |
| **Strict Exact Match** | Compara literalmente a resposta real com a esperada. |
| **Normalized Exact Match** | Normaliza Unicode, caixa, pontuação e espaços antes da comparação. |
| **Contains Expected Terms** | Exige uma alternativa de cada grupo de termos esperado. |

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
        expectedTermGroups: [
            ['caminho mais curto', 'menor caminho'],
            ['fila de prioridade', 'priority queue', 'min-heap'],
        ],
        metadata: ['category' => 'graphs'],
    ),
], name: 'Algorithms');
```

Para um caso negativo, use `expectNoEvidence: true`. Ele passa apenas quando nenhum contexto final é selecionado:

```php
new EvaluationCase(
    id: 'wesley',
    question: 'Como funciona o algoritmo Wesley?',
    expectNoEvidence: true,
);
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

Resultados usam quatro estados: `PASSED`, `FAILED`, `ERROR` e `NOT_APPLICABLE`. Erros técnicos não são contabilizados como respostas incorretas, e métricas sem gabarito permanecem `n/a`.

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

## 💻 Baseline de algoritmos

### Retrieval offline

```bash
php examples/evaluation/evaluate-algorithms-retrieval.php
```

Esse benchmark é determinístico, usa o `Retriever` real e não instancia `RagPipeline` nem `LanguageModel`. Ele mede chunks e documentos separadamente, imprime denominadores e inclui dez casos positivos e três casos negativos.

O pipeline principal offline usa busca híbrida, pesos RRF `vector=0.5` e `lexical=1.0`, sem `ContextRelevancePolicy` baseada em distância e sem expansão de vizinhos. O cabeçalho do relatório imprime essa configuração.

`DemoEmbeddingProvider` é apenas uma implementação determinística para exercitar o pipeline. O resultado `vector only` desse benchmark não representa `bge-m3`, OpenAI embeddings ou qualquer modelo semântico real.

### Diagnóstico por estágio e ablações

```bash
php examples/evaluation/diagnose-algorithms-retrieval.php
```

O diagnóstico mostra, para cada caso golden:

- rankings vetorial e lexical brutos;
- resultado da fusão RRF;
- candidatos depois da expansão;
- candidatos preservados pela `ContextRelevancePolicy`;
- seleção final e motivo de remoção;
- Recall@5, @10, @20 e @50;
- ablações vector-only, lexical-only, híbridas, query rewriting e relevance policy;
- distribuição das melhores distâncias para casos positivos e negativos.

A análise detalhada ativa propositalmente a policy de distância para mostrar onde ela remove evidências. Ela é uma **ablação experimental**, diferente do pipeline principal. Na matriz, somente as variantes identificadas com `relevance` habilitam essa policy.

Na arquitetura atual, a relevance policy escolhe anchors fundidos antes da expansão. Somente esses anchors são enriquecidos com vizinhos, evitando multiplicar candidatos fracos. Os snapshots `relevanceSelectedChunkIds` e `expandedChunkIds` em `RetrievalDiagnostics` tornam essa ordem observável sem recalcular o pipeline.

O baseline híbrido usa pesos configuráveis no RRF:

```php
$retriever = new Retriever(
    // ...
    lexicalStore: $store,
    rankingWeights: [
        'vector' => 0.5,
        'lexical' => 1.0,
    ],
);
```

Pela High-Level API:

```php
ContextEngine::create()->retrieval(
    hybridSearch: true,
    vectorWeight: 0.5,
    lexicalWeight: 1.0,
);
```

Quando a busca híbrida está ativa, a High-Level API não aplica a `ContextRelevancePolicy` baseada apenas em distância vetorial. Em busca exclusivamente vetorial, a seleção adaptativa continua disponível.

O store offline remove termos interrogativos e genéricos, não completa artificialmente o top-k e expõe `lexicalScore`. Consultas como `XYZ-WESLEY-999` retornam zero resultados lexicais. Isso não garante abstenção final enquanto a perna vetorial ainda produzir candidatos; a política de evidência deve ser calibrada separadamente.

### RAG live

```bash
php examples/evaluation/evaluate-algorithms-live.php
```

O modo live usa High-Level API, ingere o corpus e depende de embeddings, PostgreSQL/pgvector e LLM configurados no ambiente. Somente esse modo calcula métricas de geração.

Para Ollama, configure modelos separados:

```dotenv
CONTEXT_ENGINE_OLLAMA_EMBEDDING_MODEL=bge-m3
CONTEXT_ENGINE_OLLAMA_EMBEDDING_DIMENSIONS=1024
CONTEXT_ENGINE_OLLAMA_MODEL=llama3.1:8b
```

O primeiro modelo precisa oferecer a capacidade `embedding`; o segundo precisa oferecer `completion/chat`. Para diagnosticar um único caso sem executar todo o dataset:

```bash
php examples/evaluation/evaluate-algorithms-live.php Dijkstra
```

O nome antigo continua como alias explícito para o modo offline:

```bash
php examples/evaluation/evaluate-algorithms.php
```

## 🧱 Estabilidade do golden

O golden é localizado no conteúdo do corpus antes da busca, usando `GoldenChunkMatcher` com modo `ANY` ou `ALL`. Termos genéricos devem usar `ALL`.

IDs físicos de chunks podem mudar ao comparar estratégias de chunking. Nessa situação, preserve também gabaritos por documento, heading, páginas, metadata ou trecho esperado. O baseline mostra chunk e documento separadamente justamente para tornar essa diferença visível.

## ⚠️ Limitações

- Strict Exact Match é propositalmente literal; Normalized Exact Match é uma métrica diferente.
- Termos esperados verificam presença textual, não equivalência semântica.
- Recall exige que o dataset conheça IDs estáveis de chunks ou documentos.
- As médias ignoram métricas não aplicáveis, em vez de tratá-las como zero.
- Avaliação por LLM não faz parte desta implementação inicial.
