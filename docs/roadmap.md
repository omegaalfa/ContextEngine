# Limitações e roadmap

## Limitações atuais

- HttpClient materializa corpos; providers incluídos não fazem streaming incremental.
- `vector(n)` e extensão precisam ser provisionados externamente.
- Não há migrations, criação automática de tabela/extensão ou gestão de índices.
- Conteúdo é duplicado quando o mesmo chunk existe em espaços diferentes.
- `TextFileLoader` e `PdfDocumentLoader` são incluídos; PDF cobre somente camada textual via Poppler opcional.
- OpenAI/Ollama são os providers de embedding incluídos; as LLMs OpenAI, Ollama e Gemini são buffered.
- Não há reranking, busca híbrida lexical + vetorial ou filtros arbitrários de metadata.

## Já implementado

O retrieval atual já possui recursos além de uma busca vetorial simples:

- `HeuristicQueryRewriter` para gerar variações determinísticas da pergunta;
- `ReciprocalRankFusion` para combinar rankings de múltiplas consultas;
- `NeighborExpansion` para trazer chunks vizinhos e preservar contexto;
- `AdaptiveContextSelector` para reduzir fontes quando a melhor evidência é suficiente;
- `RetrievalDiagnostics` e `RagDiagnostics` para auditar consultas, escolhas e tempos.

Esses recursos continuam opcionais ou configuráveis. Sem configuração extra, a API simples continua funcionando como busca vetorial direta.

## Possíveis evoluções

- streaming real quando o transporte oferecer chunks;
- OCR e estrutura semântica para PDF; loaders HTML, Markdown e JSONL;
- embeddings Gemini e adapters Anthropic;
- reranking e busca híbrida lexical + vetorial;
- schema separado entre chunks e embeddings;
- observabilidade externa, métricas e benchmarks reproduzíveis;
- evaluation framework para medir qualidade do retrieval.

Esses itens não são funcionalidades disponíveis.
