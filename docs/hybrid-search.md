# Busca Híbrida (Vetorial + Lexical)

A busca híbrida combina duas fontes de ranking:

- busca vetorial via embeddings;
- busca lexical/full-text via PostgreSQL.

A fusão usa Reciprocal Rank Fusion (RRF) ponderado:

- score = sum(weight / (k + rank));
- a ordem final depende da posição em cada ranking;
- não depende do valor absoluto de distância.

## Como funciona

1. O Retriever executa as consultas vetoriais normalmente.
2. Quando habilitado, ele executa também uma busca lexical com o texto original da pergunta.
3. O ranking lexical entra com a chave interna __lexical__.
4. O mesmo componente de RRF funde todos os rankings.

A busca lexical usa websearch_to_tsquery('portuguese', :terms) e ordena por ts_rank_cd decrescente.

## Pseudo-distancia lexical

Resultados lexicais precisam ser compatíveis com VectorSearchResult. Para isso, a distancia lexical e representada como pseudo-distancia:

- pseudoDistance = 1.0 / (1.0 + lexicalRank)

Esse valor e finito, nao negativo e nao representa distancia vetorial real.

## Requisitos de schema

O schema deve conter:

- coluna gerada search_vector tsvector com to_tsvector('portuguese', content);
- indice GIN sobre search_vector.

O ContextEngine nao altera schema em runtime. A migracao deve ser aplicada externamente.

## Configuracao

Por variavel de ambiente:

- CONTEXT_ENGINE_HYBRID_SEARCH=0 (default)
- CONTEXT_ENGINE_HYBRID_SEARCH=1 (habilitado)
- CONTEXT_ENGINE_VECTOR_RANKING_WEIGHT=0.5
- CONTEXT_ENGINE_LEXICAL_RANKING_WEIGHT=1.0

Por API fluente:

- retrieval(..., hybridSearch: true, vectorWeight: 0.5, lexicalWeight: 1.0)

Precedencia:

1. configuracao explicita da API fluente;
2. variaveis do processo/Docker/CI;
3. arquivo .env;
4. default false.

## Diagnosticos

Quando habilitado, a perna lexical aparece em:

- hitsPerQuery na chave __lexical__;
- resultsByQuery na chave __lexical__;
- lexicalScore em cada resultado lexical;
- timingsMilliseconds.lexicalRetrieval.

Como a fusao e RRF, a participacao lexical tambem aparece nos QueryMatch dos resultados fundidos, com query = __lexical__.

No modo híbrido, a High-Level API desativa a `ContextRelevancePolicy` baseada em diferença de distância vetorial. Depois da fusão, a expansão é aplicada somente aos anchors preservados, antes do orçamento final.

## Limitacoes

- idioma fixo portuguese no tsquery atual;
- a primeira versao roda a busca lexical apenas para a pergunta original;
- a pseudo-distancia lexical nao pode ser comparada semanticamente com distancia vetorial.
