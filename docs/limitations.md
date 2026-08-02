# 🚧 Limitações, escopo e maturidade

Nem toda fronteira é um defeito. Esta página separa decisões deliberadas, recursos ainda não implementados e características inevitáveis da infraestrutura.

## Estado de maturidade

O ContextEngine possui núcleo funcional e testes automatizados, mas continua em desenvolvimento ativo. O pacote usa `dev-main`, não possui versão estável confirmada e sua API pública pode sofrer alterações incompatíveis.

Isso não significa que o código seja apenas um protótipo; significa que adoção em cargas críticas exige pin de commit/lock file, testes da aplicação consumidora, observabilidade e plano de atualização. Não há evidência no repositório para afirmar uso em produção real.

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
- busca híbrida entre full-text e vetor;
- reranking de resultados;
- provider Gemini;
- modelo de linguagem Ollama;
- streaming incremental nos providers incluídos;
- filtros arbitrários de metadata;
- retry, backoff, rate limiting e observabilidade padronizados;
- adapters concretos de cache Redis/APCu/arquivo dentro deste pacote.

Essas capacidades podem ser adicionadas pela aplicação atrás dos contratos existentes quando aplicável. Não aparecem como recursos nativos até haver implementação e testes.

## Características da infraestrutura

### Dimensão física

A coluna PostgreSQL `vector(n)` possui dimensão fixa. O fingerprint identifica logicamente um espaço, mas não transforma `vector(1536)` em `vector(768)`. Modelos com dimensões diferentes exigem schema compatível, normalmente tabela/coluna adequada à estratégia da aplicação.

### Compatibilidade vetorial

Provider, model, dimensions, revision e parameters precisam representar o mesmo `EmbeddingSpace` na ingestão e na pergunta. Vetores incompatíveis não participam da mesma busca.

### Serviços externos

OpenAI e providers futuros como Gemini exigem rede, credenciais, disponibilidade e controle de custo. Ollama evita necessariamente um serviço SaaS, mas exige processo local/remoto ativo, modelo instalado e recursos computacionais suficientes.

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
