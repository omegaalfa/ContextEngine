# 🔎 Pipeline de retrieval

> Novo em RAG? Comece por [Retrieval para iniciantes](retrieval-for-beginners.md).

## Visão rápida

    Question original
          ↓
    QueryRewriter
          ↓
    N consultas → VectorStore::search
          ↓
    Reciprocal Rank Fusion + deduplicação
          ↓
    vizinhos opcionais
          ↓
    orçamento final → ContextPromptBuilder

Sem configuração, IdentityQueryRewriter devolve apenas a pergunta original e NeighborExpansion fica desativada.

## Planejamento

HeuristicQueryRewriter é opt-in, determinístico e não chama LLM. A pergunta original permanece em primeiro lugar. Variantes adicionais preservam identificadores com sublinhado ou hífen, maiúsculas, termos delimitados e expressões como e[i,j]. Não há dicionário rígido de algoritmos e palavras como PHP, Python, explique ou compare não são removidas.

## Ordem e limites

| Limite | Papel |
|---|---|
| RetrievalPolicy::limit | máximo por consulta |
| fusedLimit | máximo após fusão |
| contextChunkLimit | máximo final, incluindo vizinhos |
| maximumContextCharacters | soma dos conteúdos finais |

    planejar → buscar → filtrar → RRF/deduplicar
    → fusedLimit → vizinhos → orçamento → prompt

O orçamento descarta chunks inteiros; nunca corta uma fonte silenciosamente.

## Sele��o adaptativa opcional

Quando `ContextRelevancePolicy` est� habilitada, a sele��o adaptativa atua depois dos vizinhos e antes do or�amento. Ela usa o melhor resultado como refer�ncia, preserva cobertura adicional e registra um motivo por candidato.

Sem a pol�tica, o fluxo anterior permanece intacto. A configura��o e as limita��es est�o em [Sele��o adaptativa de contexto](adaptive-context-selection.md).

## RRF

O score acumula 1 / (60 + rank). Um chunk encontrado por várias consultas acumula score e preserva consultas, ranks e distâncias em QueryMatch. Empates usam menor distância e depois chunk ID.

## Vizinhos seguros

PgVectorStore exige position e documentVersion. A query de vizinhos filtra no SQL tenant, collection, status, documento, versão ativa, identidade completa do espaço e intervalo de posição. Ela não usa hash, distância ou ordem incidental. Vizinhos são ordenados, deduplicados e marcados como neighbor.

## Diagnóstico

Retriever::retrieveWithDiagnostics retorna RetrievalOutcome com pergunta original, consultas, hits por consulta, deduplicação, ranking, vizinhos, seleção, descartes, caracteres e tempos. RagPipeline::askWithDiagnostics acrescenta tamanho do prompt, tempo do builder, tempo do modelo e total. ask continua sendo a API simples.

O campo removedByMaximumDistance é null no adapter atual porque PgVectorStore aplica esse corte internamente depois de receber as linhas; o Retriever não vê os candidatos eliminados. Não se fabrica uma contagem zero. Essa limitação também significa que um threshold restritivo pode devolver menos itens que o limite após o LIMIT SQL.

Execute sem chamar o LLM:

    php examples/retrieval-diagnostics.php "Converta optimal_bst para PHP 8.4."

O exemplo imprime consultas, hits, ranking fundido, fontes finais, tamanho e tempos sem revelar credenciais ou o prompt completo.

## Roteiro de depuração

    arquivo original → Document → Chunk → persistência
    → resultados por consulta → RRF → vizinhos/orçamento
    → fontes → prompt → resposta

Inspecione primeiro IDs, fingerprint, posição, versão, tamanhos e decisões. Prompt e conteúdo integral devem ser opt-in em ambiente seguro.
