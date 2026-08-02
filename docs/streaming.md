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

`OpenAILanguageModel`, `OllamaLanguageModel` e `GeminiLanguageModel` são buffered e não implementam o contrato. Nenhum provider incluído oferece streaming hoje.

Uma implementação futura deve usar transporte que entregue chunks reais, manter sequence não negativa, emitir conteúdo não vazio ou marcador final e nunca converter uma completion buffered em deltas artificiais.
