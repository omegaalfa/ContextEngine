# Limitações e roadmap

## Limitações atuais

- HttpClient materializa corpos; providers incluídos não fazem streaming incremental.
- `vector(n)` e extensão precisam ser provisionados externamente.
- Não há migrations, criação automática de tabela/extensão ou gestão de índices.
- Conteúdo é duplicado quando o mesmo chunk existe em espaços diferentes.
- `TextFileLoader` é o único loader incluído.
- OpenAI/Ollama são os únicos providers de embedding incluídos; LLM incluída é OpenAI buffered.
- Não há reranking, busca híbrida ou filtros arbitrários de metadata.

## Possíveis evoluções

- streaming real quando o transporte oferecer chunks;
- loaders HTML, Markdown, JSONL e PDF;
- Gemini e Anthropic;
- reranking e busca lexical híbrida;
- schema separado entre chunks e embeddings;
- observabilidade e benchmarks reproduzíveis.

Esses itens não são funcionalidades disponíveis.
