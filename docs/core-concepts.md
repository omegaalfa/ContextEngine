# Conceitos principais

## Documento e chunk

`Document` é conteúdo de uma fonte dentro de um tenant e collection. `Chunk` é uma unidade recuperável com posição e ID lógico. Um `Chunk` criado externamente pode reutilizar o mesmo ID em tenants diferentes; por isso `chunk_id` isolado não é global.

## Embedding e espaço

`Embedding` combina uma lista finita de floats com `EmbeddingSpace`. O espaço contém provider, model, dimensions, revision e parâmetros semânticos. Seu fingerprint impede busca entre vetores incompatíveis.

## Identidade persistida

```text
tenant_id + collection + chunk_id + embedding_space_fingerprint
```

Reingerir essa identidade faz upsert. Alterar tenant, collection ou espaço cria uma linha independente. Isso permite versionamento por modelo/revisão sem sobrescrever vetores anteriores.

## Retrieval e RAG

Retrieval é a seleção top-k por distância dentro do escopo permitido. RAG adiciona os chunks encontrados ao prompt como dados não confiáveis e pede ao language model uma resposta. Distância menor é melhor nas métricas expostas pelo store.

## Collection e status

Collection é opcional na consulta (`null` não adiciona filtro), mas sempre existe em `Chunk`/banco, com padrão `default`. Status é obrigatório e usa `active` por padrão.
