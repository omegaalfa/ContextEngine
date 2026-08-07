# 🗺️ Documentação do ContextEngine

Bem-vindo! Se você nunca trabalhou com RAG, comece pelo guia inicial. Se já sabe o que procura, use as trilhas abaixo.

## 🚀 Comece aqui

| Quero... | Leia |
|---|---|
| instalar e executar a primeira busca | [Primeiros passos](getting-started.md) |
| entender RAG sem termos complicados | [Guia para iniciantes](beginner-guide.md) |
| usar a API mais simples | [High-Level API](high-level-api.md) |
| descobrir qual classe resolve meu problema | [Mapa completo da API](public-api-map.md) |
| consultar assinaturas e contratos | [Referência técnica](api-reference.md) |

## 📚 Trilhas por assunto

| Área | Guias |
|---|---|
| 🧭 **Fundamentos** | [Arquitetura](architecture.md) · [Conceitos](core-concepts.md) · [Limitações](limitations.md) |
| 📥 **Ingestão** | [Ingestão](ingestion.md) · [Parsing estrutural](document-parsing.md) · [Documentos e splitters](documents-and-splitting.md) · [PDF](pdf-ingestion.md) |
| 🧠 **Embeddings** | [Embeddings](embeddings.md) · [Providers](providers.md) · [Cache](caching.md) |
| 🔎 **Retrieval** | [Retrieval para iniciantes](retrieval-for-beginners.md) · [Retrieval](retrieval.md) · [Busca híbrida](hybrid-search.md) · [Reranking](reranking.md) · [Pipeline avançado](retrieval-pipeline.md) |
| 💬 **RAG** | [Pipeline RAG](rag-pipeline.md) · [Protocolo de prompt](prompt-protocol.md) · [Streaming](streaming.md) |
| 🗄️ **Persistência** | [Vector store](vector-store.md) · [Schema](database-schema.md) · [Docker](docker-integration.md) |
| 🧪 **Qualidade** | [Avaliação reproduzível](evaluation.md) · [Benchmark de reranking](reranking.md) · [Testes](testing.md) · [Playbook de exemplos](examples-retrieval-playbook.md) |
| 🛡️ **Produção** | [Erros](error-handling.md) · [Segurança](security.md) · [Performance](performance.md) · [Troubleshooting](troubleshooting.md) |
| 🧩 **Extensão** | [Guia de extensão](extension-guide.md) · [Concorrência](concurrency.md) · [Roadmap](roadmap.md) |

## 💡 Regra prática

```text
Aplicação comum       → ContextEngine::create()
Integração avançada   → contratos públicos
Investigação de falha → métodos *WithDiagnostics()
Comparação de versões → módulo Evaluation
```

O código em `src/` é a fonte de verdade. Guias que dependem de credenciais ou serviços externos indicam isso antes dos comandos.
