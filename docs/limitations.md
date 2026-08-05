# 🚧 Limitações, escopo e maturidade

Nem toda fronteira é um defeito. Esta página separa decisões deliberadas, recursos ainda não implementados e características inevitáveis da infraestrutura.

## Estado de maturidade

O ContextEngine possui núcleo funcional, dependências Omegaalfa versionadas e testes automatizados, mas continua em desenvolvimento ativo. A API pública ainda pode evoluir conforme a biblioteca amadurece.

Isso não significa que o código seja apenas um protótipo; significa que adoção em cargas críticas exige lock file, testes da aplicação consumidora, observabilidade e plano de atualização. Não há evidência no repositório para afirmar uso em produção real.

## Recursos já disponíveis

Estes itens fazem parte do código atual e não devem ser tratados como roadmap:

- retrieval com múltiplas consultas quando `HeuristicQueryRewriter` é habilitado;
- fusão de rankings com `ReciprocalRankFusion`;
- expansão opcional de chunks vizinhos pelo mesmo documento e versão;
- seleção adaptativa de contexto com motivos de seleção/descarte;
- orçamento final por quantidade de chunks e caracteres;
- diagnóstico de retrieval e RAG com tempos por etapa;
- política configurável para ausência de evidência;
- `GeminiLanguageModel` para resposta completa buffered.

Em termos simples: o retrieval atual não é apenas "pegar os 5 vetores mais próximos". Ele pode reformular a pergunta, buscar por mais de uma versão da pergunta, combinar os resultados, completar o trecho com vizinhos e só então montar o contexto para o LLM.

## Escopo deliberado

| Decisão | Impacto prático |
|---|---|
| Biblioteca, não aplicação | API HTTP, interface, autenticação e autorização pertencem ao consumidor. |
| Schema externo | Extensão, tabela, dimensão e índices são provisionados pela aplicação ou fixture. |
| PostgreSQL/pgvector incluído | Outros bancos exigem implementação de `VectorStore`. |
| Persistência serial | A conexão não é usada concorrentemente e nenhuma transação fica aberta durante HTTP. |
| Cache por decorator | Cache só existe quando uma implementação PSR-16 é injetada. |
| Streaming como contrato separado | Resposta buffered nunca é dividida artificialmente em deltas. |

## Recursos ainda não implementados

- OCR para PDFs escaneados e loaders para HTML, Markdown ou object storage;
- busca híbrida entre full-text/BM25 e vetor;
- reranking por cross-encoder, LLM ou serviço externo;
- embeddings Gemini;
- streaming incremental nativo ainda não disponível para `OllamaLanguageModel` e `GeminiLanguageModel`;
- filtros arbitrários de metadata;
- retry, backoff, rate limiting e observabilidade externa padronizada;
- adapters concretos de cache Redis/APCu/arquivo dentro deste pacote.

Essas capacidades podem ser adicionadas pela aplicação atrás dos contratos existentes quando aplicável. Não aparecem como recursos nativos até haver implementação e testes.

## Diferenças importantes

`ReciprocalRankFusion` não é a mesma coisa que busca híbrida. RRF combina listas de resultados, por exemplo a busca feita com a pergunta original e buscas feitas com variações da pergunta. Busca híbrida, no sentido usado no roadmap, significa combinar busca vetorial com busca textual/lexical, como full-text PostgreSQL ou BM25.

Os diagnósticos internos também não são a mesma coisa que observabilidade operacional completa. Hoje a engine mede tempos e decisões dentro de uma execução. Ainda faltam integração padronizada com logs PSR-3, métricas, tracing e painéis operacionais.

## Características da infraestrutura

### Dimensão física

A coluna PostgreSQL `vector(n)` possui dimensão fixa. O fingerprint identifica logicamente um espaço, mas não transforma `vector(1536)` em `vector(768)`. Modelos com dimensões diferentes exigem schema compatível, normalmente tabela/coluna adequada à estratégia da aplicação.

### Compatibilidade vetorial

Provider, model, dimensions, revision e parameters precisam representar o mesmo `EmbeddingSpace` na ingestão e na pergunta. Vetores incompatíveis não participam da mesma busca.

### Serviços externos

OpenAI e Gemini exigem rede, credenciais, disponibilidade e controle de custo. Ollama evita necessariamente um serviço SaaS, mas exige processo local/remoto ativo, modelo instalado e recursos computacionais suficientes.

### Conteúdo versionado

O mesmo chunk em espaços diferentes ocupa linhas diferentes e repete conteúdo/metadata. Esse custo de armazenamento preserva versões sem sobrescrever embeddings anteriores.

## Antes de produção crítica

- fixe dependências com `composer.lock` ou referência revisada;
- execute testes de integração com schema e dimensão reais;
- valide qualidade de retrieval com documentos representativos;
- configure timeouts, retry e limites de concorrência;
- proteja credenciais e derive tenant da identidade autenticada;
- monitore latência, erros, tokens e custos;
- planeje backup, migrations e índices pgvector;
- teste comportamento parcial e retomada idempotente.

Veja também [Arquitetura](architecture.md), [Troubleshooting](troubleshooting.md) e [Testes](testing.md).
