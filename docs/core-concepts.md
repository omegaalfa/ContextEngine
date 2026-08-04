# Conceitos principais

Esta é uma referência rápida. Se RAG, embedding ou busca vetorial forem conceitos novos para você, leia as explicações em três níveis — o que é, para que serve e como a engine usa — em [Primeiros passos](getting-started.md#-3-glossário-para-iniciantes).

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

## Ciclo de vida documental

A partir da v2, uma versão documental deixa de ser apenas um identificador implícito. `DocumentVersion` passa a representar um estado de publicação com:

- `DocumentVersionStatus` (`draft`, `staged`, `active`, `superseded`, `archived`);
- `revision` para ordenar a história do documento;
- `validFrom` e `validUntil` para vigência temporal;
- `supersedesVersionId` para preservar a relação entre versões.

Esse modelo é a base para auditoria, rollback e retrieval temporal, sem quebrar o comportamento atual quando nenhuma política temporal é usada.

## Política de seleção de versões

O retriever pode receber `VersionSelectionPolicy` para controlar quais versões entram no contexto. As opções são:

Além disso, os resultados recuperados podem carregar `VersionedSourceProvenance`, permitindo que a aplicação e o prompt saibam exatamente qual versão documental foi usada, em que revisão, com qual status e dentro de qual janela temporal.

- `active()` para a seleção padrão;
- `validAt($date)` para considerar versões vigentes em um instante;
- `allVersions()` para auditoria e diff.

A política é opcional e não altera o contrato público principal; quando ausente, a biblioteca preserva o comportamento atual.
