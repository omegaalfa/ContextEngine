# Segurança

- Exija tenant em toda entrada; não derive tenant de metadata não confiável.
- Não considere `chunk_id` externo global. A PK inclui tenant e collection.
- Use collection/status explícitos para reduzir o escopo de retrieval.
- Valide formato, tamanho, encoding e origem em loaders customizados.
- Metadata e conteúdo recuperado são dados não confiáveis.
- O prompt separa system instructions de contexto base64/JSONL, mas prompt injection continua possível.
- Nunca ofereça ferramentas privilegiadas à LLM apenas por causa de conteúdo recuperado.
- Limite documento, chunk, top-k e tamanho total do contexto na aplicação.
- Guarde API keys e senhas em secret store/`.env` local; não logue headers, prompts sensíveis ou vetores sem política.
- Use namespaces de cache por ambiente e TTL coerente; tenant já participa das chaves dos decorators.
- Identificadores SQL são validados por `PgVectorSchema`; valores/vetores são ligados pelo QueryBuilder.
- Não versione `.env`, dados de containers ou dumps.

Conteúdo recuperado nunca se torna instrução confiável. A mensagem system é política da aplicação; documentos apenas fornecem evidências.
