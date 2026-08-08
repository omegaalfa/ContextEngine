# Pipeline de retrieval

> Novo em RAG? Comece por [Retrieval para iniciantes](retrieval-for-beginners.md). Este documento explica a pipeline real usada pelo código atual.

## Visão rápida

Retrieval é a etapa que procura evidências antes de chamar o LLM. Pense nele como um bibliotecário técnico: ele recebe a pergunta, procura trechos candidatos, remove repetidos, completa o contexto quando necessário e entrega apenas as fontes mais úteis para o prompt.

```text
Pergunta original
      ↓
QueryRewriter
      ↓
uma ou mais buscas no VectorStore
      ↓
Reciprocal Rank Fusion + deduplicação
      ↓
vizinhos opcionais
      ↓
seleção adaptativa opcional
      ↓
orçamento final
      ↓
ContextPromptBuilder
```

Sem configuração extra, `IdentityQueryRewriter` devolve apenas a pergunta original e `NeighborExpansion` fica desativada. Ou seja: a API simples continua previsível. Os recursos avançados entram quando a aplicação os configura.

## 1. QueryRewriter

`QueryRewriter` decide quantas formas da pergunta serão pesquisadas. O modo simples usa somente a pergunta original.

Quando `HeuristicQueryRewriter` é habilitado, ele cria variações determinísticas da pergunta para aumentar a chance de encontrar o trecho certo. Ele não chama LLM e não tenta ser inteligente demais; a ideia é gerar formas úteis e previsíveis da mesma intenção.

Exemplo conceitual:

```text
"Como implementar Fibonacci com recursão?"
      ↓
"Como implementar Fibonacci com recursão?"
"implementar Fibonacci recursão"
"Fibonacci recursive function"
"Fibonacci"
```

A pergunta original sempre fica em primeiro lugar. Isso evita perder a intenção exata do usuário.

## 2. Busca vetorial

Para cada consulta planejada, o `Retriever` gera um embedding da pergunta usando o mesmo `EmbeddingProvider` da ingestão. Depois chama `VectorStore::search()`.

No `PgVectorStore`, filtros importantes entram no SQL:

- tenant;
- collection, quando definida;
- status;
- estado de ingestão ativo;
- provider, modelo, dimensão, revision e fingerprint do `EmbeddingSpace`.

Isso significa que a busca não pega vetores incompatíveis para descartar depois em PHP. O banco já procura dentro do espaço correto.

## 3. RRF

RRF significa `Reciprocal Rank Fusion`. Em linguagem simples: quando várias buscas retornam listas diferentes, o RRF monta uma lista final mais estável.

```text
Busca A: chunk-1, chunk-2, chunk-3
Busca B: chunk-2, chunk-4, chunk-1

RRF percebe que chunk-1 e chunk-2 aparecem em mais de uma lista
e calcula uma ordem final combinando posição e repetição.
```

O score acumula `1 / (60 + rank)`. Um chunk encontrado por várias consultas acumula score e preserva consultas, ranks e distâncias em `QueryMatch`. Empates usam menor distância e depois chunk ID.

RRF não é, sozinho, uma busca híbrida: ele é o mecanismo que combina listas. No modo híbrido atual, essas listas vêm da busca vetorial e de `PgVectorStore::searchLexical()`. O RRF também continua útil para reunir resultados da pergunta original e de suas variações.

## 4. Limites da pipeline

Existem limites em etapas diferentes. Eles não são iguais:

| Limite | Papel |
|---|---|
| `RetrievalPolicy::limit` | máximo por consulta vetorial; também é o fallback lexical compatível |
| `lexicalCandidateLimit` | máximo da busca lexical, independente do vetor |
| `fusedLimit` | máximo depois da fusão RRF |
| `rerankerCandidateLimit` | quantos candidatos do RRF chegam ao reranker |
| `contextChunkLimit` | máximo final de chunks enviados ao prompt |
| `maximumContextCharacters` | tamanho máximo somado dos conteúdos finais |

Fluxo resumido:

```text
planejar → buscar → filtrar → RRF/deduplicar
→ fusedLimit → rerankerCandidateLimit → relevância → abstenção → vizinhos → orçamento → prompt
```

Na High-Level API, `retrievalLimit` continua sendo o limite vetorial. `lexicalCandidateLimit` e `rerankerCandidateLimit` são opcionais. Assim é possível executar vetor 30 + lexical 30 → RRF 30 → reranker 5 → contexto 5. Sem os novos argumentos, o comportamento anterior é preservado.

### Evidência e abstenção

`AbstentionPolicy` responde se há evidência suficiente para entregar contexto. `HybridEvidencePolicy` é a implementação conservadora incluída. Ela não depende de um threshold global de distância: considera quantidade de candidatos, apoio lexical, termos nomeados e scores disponíveis. `RetrievalDiagnostics` registra `abstained`, `abstentionReason` e `abstentionSignals`.

### Idioma lexical

`textSearchConfiguration` escolhe a configuração full-text do PostgreSQL. O default permanece `portuguese`; use `english` para inglês ou `simple` para termos técnicos. O valor precisa ser um identificador seguro e uma configuração instalada no banco.

O orçamento descarta chunks inteiros. Ele não corta uma fonte no meio silenciosamente.

## 5. Vizinhos

Às vezes o melhor chunk contém só metade da explicação. `NeighborExpansion` permite trazer chunks próximos no mesmo documento, pela posição.

Exemplo:

```text
chunk 10: introduz a regra
chunk 11: contém a resposta principal
chunk 12: mostra exceções
```

Se o chunk 11 for encontrado, a engine pode trazer 10 e/ou 12 para dar contexto mais completo. Isso é útil em PDFs, manuais e livros, onde uma explicação frequentemente ocupa vários trechos.

No `PgVectorStore`, vizinhos são filtrados por tenant, collection, status, documento, versão, `EmbeddingSpace` e posição. A busca de vizinhos não usa distância vetorial; ela usa a ordem do documento.

## 6. Seleção adaptativa

Quando `ContextRelevancePolicy` está habilitada, a seleção adaptativa atua depois dos vizinhos e antes do orçamento final. Ela usa o melhor resultado como referência e tenta evitar que fontes fracas ou repetidas adicionem ruído ao prompt.

Exemplo simples: se o primeiro chunk já responde claramente à pergunta e o segundo está muito mais distante, o segundo pode ser descartado. Mas se uma fonte distante cobre uma parte que a primeira não cobre, ela pode ser mantida.

Cada decisão fica registrada com um motivo, como:

- `primary_evidence`;
- `same_document_support`;
- `neighbor_context`;
- `additional_coverage`;
- `distance_gap`;
- `duplicate_evidence`;
- `source_limit`;
- `context_budget`.

Sem a política, o fluxo anterior permanece intacto. A configuração e as limitações estão em [Seleção adaptativa de contexto](adaptive-context-selection.md).

## 7. Diagnósticos

`Retriever::retrieveWithDiagnostics()` retorna `RetrievalOutcome` com:

- pergunta original;
- consultas executadas;
- hits por consulta;
- resultados por consulta;
- chunks removidos por duplicação;
- ranking final após RRF;
- vizinhos incluídos;
- fontes finais;
- descartes;
- quantidade de caracteres;
- tempos por etapa.

`RagPipeline::askWithDiagnostics()` acrescenta tamanho do prompt, tempo do prompt builder, tempo do modelo e tempo total. `ask()` continua sendo a API simples para quem não precisa auditar.

Esses diagnósticos ajudam a responder perguntas como:

```text
Por que esta fonte foi usada?
Por que só uma fonte chegou ao LLM?
A busca retornou alguma coisa antes do threshold?
O tempo foi gasto no banco, no embedding ou no modelo?
```

Eles não substituem observabilidade externa de produção, como logs PSR-3, métricas, tracing ou dashboards.

## 8. Depuração prática

Execute sem chamar o LLM:

```bash
php examples/retrieval-diagnostics.php "Converta optimal_bst para PHP 8.4."
```

Roteiro para investigar uma resposta ruim:

```text
arquivo original
      ↓
Document
      ↓
Chunk
      ↓
persistência
      ↓
resultados por consulta
      ↓
RRF
      ↓
vizinhos + seleção + orçamento
      ↓
fontes finais
      ↓
prompt
      ↓
resposta
```

Inspecione primeiro tenant, collection, status, fingerprint, posição, versão, tamanho dos chunks e motivos de seleção. Prompt completo e conteúdo integral devem ser exibidos apenas em ambiente seguro, porque podem conter dados sensíveis.
