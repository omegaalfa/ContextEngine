# Streaming

## Conceitos

- **Buffered:** transporte recebe o corpo inteiro e só então retorna.
- **Incremental real:** deltas são entregues enquanto chegam da rede.
- **Falso streaming:** dividir uma resposta pronta; o pacote deliberadamente não faz isso.

`StreamingLanguageModel` é independente de `LanguageModel`:

```text
stream(list<ChatMessage> $messages): iterable<AnswerDelta>
```

`RagPipeline` aceita um `StreamingLanguageModel` opcional no quarto argumento. `stream(Question|string, ?string): iterable` recupera contexto e delega ao provider incremental. Sem essa capacidade, lança `StreamingNotSupportedException`; não retorna vazio nem chama `ask()`.

No uso diario, o caminho padrao continua sendo `ask(...)` (resposta completa buffered). O streaming incremental acontece somente quando a aplicacao chama `stream(...)` explicitamente.

`OpenAILanguageModel` implementa `StreamingLanguageModel` com SSE incremental real usando `omegaalfa/http-client` `v1.0.2` (`streamSsePost`).

`OllamaLanguageModel` e `GeminiLanguageModel` seguem buffered e não implementam streaming incremental.

No fluxo OpenAI, cada evento SSE pode gerar um `AnswerDelta` imediatamente. O marcador `[DONE]` gera o delta final (`final=true`) sem criar streaming artificial.
