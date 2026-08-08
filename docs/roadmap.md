# Limitações e roadmap

## Limitações atuais

- HttpClient materializa corpos; providers incluídos não fazem streaming incremental.
- `vector(n)` e extensão precisam ser provisionados externamente.
- Não há migrations, criação automática de tabela/extensão ou gestão de índices.
- Conteúdo é duplicado quando o mesmo chunk existe em espaços diferentes.
- `TextFileLoader` e `PdfDocumentLoader` são incluídos; PDF cobre somente camada textual via Poppler opcional.
- OpenAI/Ollama são os providers de embedding incluídos; as LLMs OpenAI, Ollama e Gemini são buffered.
- Não há filtros arbitrários de metadata nem cross-encoder local executado dentro da aplicação.

## Já implementado

O retrieval atual já possui recursos além de uma busca vetorial simples:

- `HeuristicQueryRewriter` para gerar variações determinísticas da pergunta;
- busca híbrida com a perna vetorial de `PgVectorStore::search()` e a perna lexical de `PgVectorStore::searchLexical()`;
- `ReciprocalRankFusion` para combinar rankings de múltiplas consultas;
- `HybridEvidencePolicy` para evitar contexto sem evidência suficiente;
- contrato `Reranker`, implementação textual determinística e cross-encoder remoto com `CohereReranker`;
- `NeighborExpansion` para trazer chunks vizinhos e preservar contexto;
- `AdaptiveContextSelector` para reduzir fontes quando a melhor evidência é suficiente;
- `RetrievalDiagnostics` e `RagDiagnostics` para auditar consultas, escolhas e tempos.

Esses recursos continuam opcionais ou configuráveis. Sem configuração extra, a API simples continua funcionando como busca vetorial direta.

## Possíveis evoluções

- streaming real quando o transporte oferecer chunks;
- OCR e estrutura semântica para PDF; loaders HTML, Markdown e JSONL;
- embeddings Gemini e adapters Anthropic;
- adapters adicionais de reranking e cross-encoder local;
- schema separado entre chunks e embeddings;
- observabilidade externa, métricas e benchmarks reproduzíveis;
- ampliação dos benchmarks e avaliadores para novos corpora e providers.

Esses itens não são funcionalidades disponíveis.
