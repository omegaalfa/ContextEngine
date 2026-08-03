# Sele��o adaptativa de contexto

> Entregue evid�ncia suficiente ao modelo sem carregar ru�do desnecess�rio.

## O problema

Uma busca pode encontrar cinco chunks, mas o primeiro j� pode responder completamente � pergunta. Enviar todos ao LLM aumenta tokens e pode adicionar ru�do.

~~~text
retrieval atual:      5 candidatos -> or�amento -> at� 5 fontes
sele��o adaptativa:   5 candidatos -> relev�ncia -> somente fontes �teis
~~~

> [!IMPORTANT]
> A sele��o � opcional e vem desativada. Sem ativa��o, o comportamento anterior � preservado.

## Como a decis�o funciona

O melhor resultado � a evid�ncia principal e sua dist�ncia vira a refer�ncia:

~~~text
melhor dist�ncia = 0.12
maximumDistanceGap = 0.08
faixa normal = at� 0.20
~~~

Um candidato fora da faixa � descartado, exceto quando acrescenta um termo significativo da pergunta ainda ausente das fontes selecionadas. Essa cobertura � lexical e determin�stica: n�o chama LLM, n�o faz BM25, n�o executa nova busca e n�o substitui o ranking vetorial.

O seletor respeita m�nimo e m�ximo de fontes, avalia primeiro o documento principal, preserva vizinhos �teis, elimina redund�ncia e entrega o resultado ao or�amento existente.

## Posi��o no pipeline

~~~text
busca vetorial
      |
RRF e deduplica��o
      |
expans�o de vizinhos
      |
sele��o adaptativa
      |
limite de chunks e caracteres
      |
prompt
~~~

O or�amento final continua soberano. Uma fonte relevante ainda pode ser descartada se n�o couber em `contextChunkLimit` ou `maximumContextCharacters`.

## Configura��o pelo Bootstrap

~~~dotenv
CONTEXT_ENGINE_ADAPTIVE_CONTEXT_SELECTION=1
CONTEXT_ENGINE_CONTEXT_MAXIMUM_DISTANCE_GAP=0.08
CONTEXT_ENGINE_CONTEXT_MINIMUM_SOURCES=1
CONTEXT_ENGINE_CONTEXT_MAXIMUM_SOURCES=5
CONTEXT_ENGINE_CONTEXT_PREFER_SAME_DOCUMENT=1
~~~

| Op��o | Responsabilidade |
|---|---|
| `ADAPTIVE_CONTEXT_SELECTION` | ativa a pol�tica |
| `MAXIMUM_DISTANCE_GAP` | diferen�a tolerada em rela��o ao melhor |
| `MINIMUM_SOURCES` | m�nimo desejado quando houver candidatos |
| `MAXIMUM_SOURCES` | m�ximo antes do or�amento global |
| `PREFER_SAME_DOCUMENT` | avalia primeiro apoio do documento principal |

## Composi��o manual

~~~php
$retriever = new Retriever(
    embeddings: $embeddings,
    store: $store,
    policy: $retrievalPolicy,
    contextChunkLimit: 5,
    maximumContextCharacters: 18_000,
    contextRelevancePolicy: new ContextRelevancePolicy(
        maximumDistanceGap: 0.08,
        minimumSources: 1,
        maximumSources: 5,
        preferSameDocument: true,
    ),
);
~~~

Passe `null` ou omita `contextRelevancePolicy` para manter a sele��o sequencial anterior.

## Motivos registrados

Cada candidato recebe uma decis�o em `RetrievalDiagnostics::contextSelection`:

| Motivo | Significado |
|---|---|
| `primary_evidence` | melhor evid�ncia e refer�ncia de dist�ncia |
| `same_document_support` | completa a pergunta no documento principal |
| `neighbor_context` | vizinho necess�rio para completar o trecho |
| `additional_coverage` | acrescenta parte ainda ausente da pergunta |
| `distance_gap` | distante e sem cobertura nova |
| `duplicate_evidence` | redundante ou sem contribui��o nova |
| `source_limit` | excedeu o m�ximo da pol�tica ou do contexto |
| `context_budget` | n�o coube no or�amento de caracteres |

~~~php
$outcome = $retriever->retrieveWithDiagnostics($question);

foreach ($outcome->diagnostics->contextSelection as $decision) {
    printf(
        '%s chunk=%s motivo=%s' . PHP_EOL,
        $decision->selected ? 'selecionado' : 'descartado',
        $decision->chunkId,
        $decision->reason->value,
    );
}
~~~

## Exemplo comparativo

~~~bash
php examples/adaptive-context-comparison.php
~~~

O exemplo usa os mesmos candidatos nos dois modos. A sele��o anterior mant�m o conjunto permitido pelo or�amento; a adaptativa conserva Fibonacci e descarta os trechos de heap e Python.

## Limites honestos

A pol�tica n�o compreende semanticamente a pergunta como uma LLM. A cobertura adicional compara termos significativos da pergunta com o conte�do. Sin�nimos, par�frases ou evid�ncia impl�cita podem n�o ser reconhecidos.

Use diagn�sticos para auditar decis�es, ajuste os limites com dados reais e aumente `minimumSources` em perguntas amplas. Ela reduz ru�do de maneira barata e previs�vel, mas n�o � reranking sem�ntico.
