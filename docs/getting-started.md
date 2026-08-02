<div align="center">

# Ω ContextEngine

### Do primeiro documento à primeira resposta RAG

**Um guia visual, completo e amigável para quem está começando**

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-pgvector-4169E1?logo=postgresql&logoColor=white)](https://github.com/pgvector/pgvector)
[![Providers](https://img.shields.io/badge/providers-pluggable-8B5CF6)](#-10-escolhendo-providers)
[![License MIT](https://img.shields.io/badge/license-MIT-22C55E)](../LICENSE)

```text
DOCUMENTOS  →  CONHECIMENTO PESQUISÁVEL  →  PERGUNTA  →  RESPOSTA COM FONTES
```

</div>

Este guia ensina a construir um sistema que lê uma política de reembolso, prepara seu conteúdo para pesquisa e responde perguntas usando os trechos encontrados. Tudo foi conferido contra o código atual.

> [!IMPORTANT]
> **Estado atual:** o núcleo é funcional e possui testes automatizados, mas está em desenvolvimento ativo, usa `dev-main` e ainda não possui versão estável confirmada. A API pode mudar; valide cuidadosamente antes de cargas críticas. A aplicação fornece banco, credenciais, configuração e interface.

---

## 🗺️ Escolha sua jornada

| | Jornada | Você aprenderá |
|---:|---|---|
| **01** | [Entender](#-1-o-que-é-o-contextengine) | O problema, os conceitos e o vocabulário essencial |
| **02** | [Preparar](#-4-antes-de-começar) | PHP, Composer, PostgreSQL, pgvector e providers |
| **03** | [Construir](#-11-configurando-as-dependências) | Como conectar cada componente da biblioteca |
| **04** | [Ingerir](#-13-documento-de-exemplo) | Como transformar um arquivo em conhecimento pesquisável |
| **05** | [Perguntar](#-18-fazendo-uma-pergunta) | Retrieval, resposta e fontes utilizadas |
| **06** | [Operar](#-21-utilizando-cache) | Cache, atualizações, erros e diagnóstico |
| **07** | [Executar](#-26-exemplo-completo-final) | Um exemplo completo, copiável e executável |

### O resultado final

```text
┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│ política.txt     │ →  │ PostgreSQL       │ →  │ “Qual é o prazo?”│
│ seus documentos │    │ + pgvector       │    │                  │
└──────────────────┘    └──────────────────┘    └────────┬─────────┘
                                                        ↓
                                               ┌──────────────────┐
                                               │ Resposta         │
                                               │ + fontes usadas  │
                                               └──────────────────┘
```

---

## 🌱 1. O que é o ContextEngine

Imagine uma empresa com manuais, regulamentos e procedimentos. Um funcionário pergunta “Em quanto tempo devo solicitar um reembolso?” e quer uma resposta baseada nesses arquivos.

O ContextEngine fornece peças PHP para ler documentos, dividi-los, prepará-los para pesquisa por significado, armazená-los, encontrar trechos relacionados e pedir a uma inteligência artificial que responda com base nas fontes.

Ele é uma **biblioteca PHP**, não uma aplicação pronta. Não possui tela, API HTTP, painel ou autenticação.

```text
O ContextEngine é o motor. Sua aplicação é o carro completo.
```

## 🎯 2. O problema resolvido

Se uma empresa possui 500 documentos, enviar todos à IA em cada pergunta seria lento, caro e poderia ultrapassar o limite do modelo. O ContextEngine prepara um índice inteligente:

```text
Documentos → divisão em trechos → transformação em números → armazenamento
Pergunta → transformação em números → procura por significado
         → envio dos melhores trechos à IA → resposta
```

Os nomes técnicos são **ingestão**, **chunking**, **embeddings**, **busca vetorial**, **retrieval** e **RAG**.

## 📚 3. Glossário para iniciantes

### RAG

**O que é.** RAG é a sigla de *Retrieval-Augmented Generation*, ou geração aumentada por recuperação. É uma maneira de fazer uma inteligência artificial consultar informações selecionadas antes de elaborar a resposta. A IA não passa a “memorizar” os documentos; ela recebe os trechos relevantes junto com a pergunta atual.

**Para que serve.** Um modelo de linguagem conhece padrões aprendidos durante seu treinamento, mas não conhece automaticamente os documentos privados ou atualizados de uma empresa. O RAG funciona como uma prova com consulta: antes de responder, a pessoa recebe as páginas certas do livro. Isso reduz respostas baseadas apenas no conhecimento geral do modelo e permite apresentar as fontes consultadas.

**Como o ContextEngine usa.** `RagPipeline` organiza a fase de perguntas. Ele pede ao `Retriever` que localize chunks, entrega pergunta e resultados ao `ContextPromptBuilder`, chama o `LanguageModel` e devolve `Answer` com texto e fontes. Essa fase acontece depois que os documentos já foram ingeridos.

### Document

**O que é.** `Document` representa uma unidade de conteúdo entregue à biblioteca. Pode ser um manual inteiro, uma seção ou um parágrafo, dependendo do loader. Além do texto, carrega ID, tenant, collection, status e metadata.

**Para que serve.** O conteúdo precisa chegar à pipeline com identidade e escopo. Sem isso, a biblioteca não saberia a qual empresa pertence, como rastrear sua origem ou se está ativo. Pense no documento como uma pasta etiquetada antes de entrar num arquivo.

**Como o ContextEngine usa.** O `DocumentLoader` produz documentos no início da ingestão. O `TextSplitter` lê cada um e gera chunks que preservam seu `documentId`, tenant, collection, status e metadata. Um `Document` não é criado durante uma pergunta.

### Loader

**O que é.** Loader é o componente que fornece objetos `Document`. Ele pode ler um arquivo, uma API, uma fila ou um banco. `TextFileLoader` é a implementação pronta para texto.

**Para que serve.** Fontes de dados possuem formatos e maneiras de acesso diferentes. Separar o loader impede que a pipeline precise saber como abrir cada origem. Ele é como a porta de recebimento: tudo o que entra é convertido para o mesmo formato antes de seguir.

**Como o ContextEngine usa.** `IngestionPipeline::ingest()` recebe um `DocumentLoader` e percorre `load()`. O loader pronto lê blocos separados por linhas vazias, cria IDs e metadata de origem. O carregamento ocorre somente ao adicionar ou atualizar conhecimento, nunca durante a busca de uma pergunta.

### Metadata

**O que é.** Metadata são informações descritivas sobre um documento ou chunk que não fazem parte do texto principal, como arquivo de origem, categoria ou data.

**Para que serve.** Funciona como a ficha de catálogo de uma biblioteca: ajuda a rastrear e exibir a origem sem misturar essas informações ao conteúdo. Não é o embedding e não substitui tenant ou collection.

**Como o ContextEngine usa.** `Document` e `Chunk` carregam metadata; o `PgVectorStore` a grava como JSONB e a reconstrói no retrieval. `TextFileLoader` adiciona `source` com o caminho do arquivo. O filtro arbitrário por metadata ainda não faz parte da busca atual.

### Ingestão

**O que é.** Ingestão é a preparação completa dos documentos para pesquisas futuras. Não é apenas fazer upload nem fazer uma pergunta.

**Para que serve.** Um texto bruto ainda não está organizado para comparação semântica. A ingestão realiza antecipadamente o trabalho mais caro, para que perguntas futuras pesquisem dados já preparados. É como catalogar e colocar livros nas prateleiras antes de abrir uma biblioteca ao público.

**Como o ContextEngine usa.** Durante a ingestão, a engine recebe documentos do loader, divide em chunks, agrupa lotes, gera embeddings, valida os resultados, grava texto e vetores no store e produz `IngestionReport`. Ela acontece quando um documento é adicionado ou atualizado. Não acontece novamente apenas porque um usuário fez uma pergunta, embora a pergunta gere seu próprio embedding.

### `IngestionPipeline`

**O que é.** É o serviço que coordena toda a ingestão. Ele não lê arquivos, gera vetores ou escreve SQL diretamente; delega cada tarefa ao contrato apropriado.

**Para que serve.** Sem um coordenador, a aplicação teria de conectar manualmente loader, splitter, provider, concorrência, validação e persistência em toda execução. A pipeline garante que essas etapas ocorram na ordem correta e que falhas produzam um relatório consistente.

**Como o ContextEngine usa.** `ingest($loader)` consome documentos incrementalmente, chama o splitter, envia batches ao `BatchEmbeddingExecutor`, associa cada embedding ao chunk correto e grava os lotes como `staged` por `VersionedVectorStore`. Ao terminar, ativa a nova versão atomicamente; se algo falhar, `IngestionException` inclui o progresso parcial e a versão anterior continua pesquisável.

### Pipeline

**O que é.** Pipeline é uma sequência de etapas conectadas, na qual a saída de uma etapa alimenta a próxima. É semelhante a uma linha de montagem: cada estação realiza uma responsabilidade específica.

**Para que serve.** Ela dá ordem e previsibilidade a um processo com muitas peças. Loader, splitter, provider e store podem ser substituídos, mas a sequência continua compreensível e testável.

**Como o ContextEngine usa.** Existem duas orquestrações principais. `IngestionPipeline` prepara e persiste conhecimento; `RagPipeline` recupera contexto e gera respostas. Elas coordenam contratos, mas não assumem as responsabilidades internas desses componentes.

### Chunk e chunking

**O que é.** Chunk é uma parte menor de um documento. *Chunking* é o processo de criar essas partes. Um chunk mantém conteúdo e informações que permitem voltar ao documento original.

**Para que serve.** Um manual inteiro pode falar de muitos assuntos e ser grande demais para uma busca precisa. Separá-lo é como dividir um livro em páginas ou capítulos: quando alguém pergunta sobre reembolso, o sistema entrega a página relevante, não o livro inteiro.

**Como o ContextEngine usa.** Durante a ingestão, `TextSplitter::split()` produz objetos `Chunk` com ID, `documentId`, posição, tenant, collection, status e metadata. Cada chunk recebe seu próprio embedding e vira uma unidade persistida e retornada como fonte.

```text
Política de reembolso
├── Chunk 1: quem pode solicitar
├── Chunk 2: prazo de 30 dias
└── Chunk 3: documentos necessários
```

### Splitter

**O que é.** Splitter é a estratégia responsável pelo chunking. O pacote inclui `RecursiveTextSplitter`, que tenta limites naturais como parágrafos, linhas, sentenças e palavras antes de cortar por caracteres.

**Para que serve.** A divisão afeta diretamente a qualidade da busca. Cortes arbitrários podem separar uma pergunta de sua resposta ou romper uma ideia. O splitter funciona como um editor que escolhe pontos sensatos para separar o livro.

**Como o ContextEngine usa.** `IngestionPipeline` chama o splitter para cada `Document`. Ele produz chunks por generator, sem materializar tudo, e pode repetir uma pequena parte entre chunks por meio do overlap. Splitter só participa da ingestão.

**Comparação conceitual:**

```text
SEM CHUNKING                       COM CHUNKING
Manual de 500 páginas              Manual de 500 páginas
        ↓                                  ↓
Conteúdo inteiro                   Partes menores
        ↓                                  ↓
Mais tokens, custo e ruído         Busca escolhe 3 ou 5 chunks
                                           ↓
                                   Só os escolhidos vão ao prompt
```

Chunks pequenos demais podem perder contexto. Chunks grandes demais podem misturar assuntos e reduzir a precisão.

### Vetor e dimensão

**O que é.** Neste guia, vetor é uma lista ordenada de números, como `[0.12, -0.44, 0.91]`. Dimensão é quantos números existem nessa lista.

**Para que serve.** Os números dão ao computador uma forma calculável de comparar textos. A dimensão precisa ser fixa para que as coordenadas pertençam ao mesmo tipo de mapa.

**Como o ContextEngine usa.** `Embedding` valida que seus valores são numéricos, finitos e têm exatamente `EmbeddingSpace::dimensions` posições. O provider OpenAI incluído usa 1.536 por padrão; o schema `vector(n)` precisa usar o mesmo `n`.

### Embedding

**O que é.** Embedding é um vetor produzido para representar características do significado de um texto. Pense nele como as coordenadas desse texto em um mapa muito grande.

**Para que serve.** Ele permite comparar sentidos, e não apenas palavras iguais. “Pedir meu dinheiro de volta” pode ficar perto de “solicitar reembolso”, mesmo sem repetir os mesmos termos. Embedding não é resumo, criptografia ou substituto do texto original.

**Como o ContextEngine usa.** Na ingestão, cada chunk vira `Embedding` e é armazenado com o texto. Na pergunta, o mesmo provider transforma a pergunta em outro embedding. O vector store compara esse vetor com os vetores dos chunks e encontra os mais próximos.

**Exemplo conceitual — números meramente ilustrativos:**

```text
"Solicitar reembolso"
        ↓
EmbeddingProvider
        ↓
[0.31, -0.45, 0.18, ...]
        ↓
VectorStore → PostgreSQL + pgvector
```

O desenvolvedor normalmente não interpreta esses números manualmente. O modelo os produz e a busca vetorial faz a comparação matemática.

### Modelo de embedding

**O que é.** É o modelo treinado para transformar texto em embedding. Modelos diferentes podem produzir quantidades e significados numéricos diferentes.

**Para que serve.** Ele aplica sempre o mesmo sistema de coordenadas aos textos que serão comparados. É como escolher um serviço de mapas: todos os endereços precisam ser localizados pelo mesmo sistema.

**Como o ContextEngine usa.** O modelo é uma configuração do provider e participa de `EmbeddingSpace`. `text-embedding-3-small` é o padrão do adapter OpenAI. No Ollama ou num adapter Gemini, a aplicação precisa informar e validar modelo e dimensão reais.

### Embedding Provider

**O que é.** É uma implementação de `EmbeddingProvider`, o contrato que transforma um texto ou lote de textos em embeddings e declara seu espaço vetorial.

**Para que serve.** OpenAI, Ollama, Gemini e serviços internos possuem autenticação e payloads diferentes. O provider funciona como tradutor, permitindo que a pipeline peça embeddings sem conhecer HTTP ou o fornecedor.

**Como o ContextEngine usa.** A ingestão chama `embedBatch()` para lotes; o retriever chama `embed()` para a pergunta; cache pode decorar ambos. O provider processa um lote, mas não controla a concorrência global. O pacote inclui adapters OpenAI e Ollama; Gemini requer implementação própria atualmente.

### `EmbeddingSpace`

**O que é.** É o objeto imutável que identifica o “mapa” em que os embeddings foram criados. Reúne provider, model, dimensions, revision e parâmetros semanticamente relevantes.

**Para que serve.** Dois vetores com o mesmo tamanho ainda podem ter significados incompatíveis se vierem de modelos ou configurações diferentes. Compará-los seria como usar latitude de um mapa e longitude de outro. O espaço torna essa incompatibilidade explícita.

**Como o ContextEngine usa.** Provider declara `space()`. Embeddings carregam esse espaço; cache o inclui na chave; banco persiste suas propriedades; retrieval filtra o mesmo espaço no SQL. Uma configuração nova pode coexistir sem sobrescrever a versão anterior.

**Exemplo conceitual:**

```text
Modelo A transforma "carro" em: [ 0.12, 0.87, ...]
Modelo B transforma "carro" em: [-0.41, 0.25, ...]
```

Mesmo representando a mesma palavra, os números vieram de mapas matemáticos diferentes. Provider, model, dimensions, revision, parameters e fingerprint identificam qual mapa foi usado. O fingerprint resolve a identidade lógica; ele não altera a dimensão física da coluna `vector(n)`.

### Fingerprint

**O que é.** Fingerprint é um hash determinístico calculado pelo `EmbeddingSpace`. É uma string que funciona como impressão digital de sua configuração.

**Para que serve.** Comparar uma impressão digital é mais simples e seguro do que depender apenas do nome do modelo. Se um parâmetro semântico mudar, o fingerprint muda; se a mesma configuração for construída novamente, ele permanece igual.

**Como o ContextEngine usa.** O fingerprint participa da chave primária do banco, das chaves de cache e dos filtros de retrieval. Ele impede que um novo espaço sobrescreva silenciosamente um vetor anterior.

### Provider

**O que é.** Provider é um adapter que conecta um contrato estável da biblioteca a um serviço concreto. Pode ser de embedding ou de linguagem.

**Para que serve.** A engine não deve aprender endpoints, headers e formatos de todos os fornecedores. O provider isola essas diferenças como um adaptador de tomada: a aplicação troca o adaptador sem reconstruir o aparelho.

**Como o ContextEngine usa.** Pipelines dependem de interfaces, não de OpenAI. O pacote oferece embeddings OpenAI/Ollama e respostas completas OpenAI, Ollama e Gemini. Embeddings Gemini ainda exigem adapter próprio.

### Vector Store

**O que é.** `VectorStore` é o contrato para guardar chunks com embeddings e pesquisar os mais próximos. “Store” descreve a capacidade, não obriga um banco específico.

**Para que serve.** A aplicação precisa persistir o trabalho da ingestão e consultá-lo depois. Separar o contrato evita que domínio e pipelines dependam de SQL ou pgvector.

**Como o ContextEngine usa.** `IngestionPipeline` chama `storeBatch()`; `Retriever` envia `VectorSearchQuery` para `search()`. A aplicação também pode remover um chunk, um documento ou esvaziar uma collection por queries com tenant obrigatório. A implementação incluída é `PgVectorStore`.

### PostgreSQL e pgvector

**O que é.** PostgreSQL é um banco de dados. pgvector é uma extensão que acrescenta ao PostgreSQL um tipo `vector` e operações de distância.

**Para que serve.** O PostgreSQL guarda texto, metadata e escopo; pgvector permite encontrar coordenadas próximas. Pense num arquivo tradicional que também ganhou um GPS para localizar pontos semelhantes.

**Como o ContextEngine usa.** `PgVectorStore` traduz `EmbeddedChunk` para linhas e consultas do QueryBuilder. O banco filtra tenant, collection, status e espaço antes de devolver resultados. A aplicação cria extensão e schema externamente; a biblioteca não faz isso em runtime.

### Busca vetorial e similaridade vetorial

**O que é.** Busca vetorial compara o embedding da pergunta com embeddings armazenados. Similaridade vetorial é a ideia de medir o quanto essas coordenadas estão próximas segundo uma métrica.

**Para que serve.** Isso encontra textos relacionados mesmo quando as palavras são diferentes. É como um GPS procurando os lugares mais próximos de uma coordenada, em vez de procurar estabelecimentos pelo nome exato.

**Como o ContextEngine usa.** Depois que a pergunta vira embedding, `PgVectorStore::search()` executa nearest-neighbor com a `VectorMetric` escolhida e `RetrievalPolicy`. Essa etapa acontece durante a pergunta, não durante a ingestão.

```text
“Como pedir meu dinheiro de volta?”
“Procedimento para solicitação de reembolso”
```

### Retrieval e `Retriever`

**O que é.** Retrieval é a etapa de recuperar informações relevantes. `Retriever` é o serviço que coordena a criação do embedding da pergunta e a consulta ao vector store.

**Para que serve.** Busca vetorial isolada ainda precisa dos filtros corretos e de uma pergunta no espaço compatível. O retriever funciona como um bibliotecário: recebe a pergunta, procura na seção da empresa certa e entrega os capítulos mais úteis.

**Como o ContextEngine usa.** `RagPipeline` chama `retrieve($question)`. O retriever usa `EmbeddingProvider`, cria `VectorSearchQuery` com tenant, policy, collection e status, e recebe `list<VectorSearchResult>`. Ele não chama o LLM.

```text
Pergunta
   ↓
Gerar embedding
   ↓
Criar consulta vetorial com filtros
   ↓
Solicitar resultados ao VectorStore
   ↓
Devolver VectorSearchResult[]
```

O Retriever é o bibliotecário: procura na empresa e collection corretas e devolve capítulos relevantes. Ele não escreve a resposta e não chama diretamente o modelo de linguagem.

### Contexto

**O que é.** Contexto é o conjunto de chunks recuperados e apresentado ao modelo junto com a pergunta.

**Para que serve.** Ele dá ao modelo informações específicas e atuais para elaborar a resposta. É o material de consulta sobre a mesa, não uma alteração permanente na memória da IA.

**Como o ContextEngine usa.** Resultados do retriever entram no `ContextPromptBuilder`. Cada fonte é delimitada e identificada. O contexto só é montado depois da busca e antes da chamada ao LLM.

### Prompt e Prompt Builder

**O que é.** Prompt é a mensagem entregue ao modelo. `ContextPromptBuilder` é o componente que monta as mensagens de sistema e usuário com instruções, pergunta e contexto.

**Para que serve.** Concatenar textos livremente gera ambiguidades e dificulta rastreamento. O builder funciona como alguém que prepara um dossiê: separa instruções das evidências e etiqueta cada fonte de forma consistente.

**Como o ContextEngine usa.** `RagPipeline` chama `build()` depois do retrieval. O builder avisa que instruções dentro dos documentos são dados não confiáveis, normaliza delimitadores e produz `ChatMessage[]`. Sua versão também pode participar da chave de cache.

**Exemplo conceitual — não é reprodução literal do prompt interno:**

```text
Instrução:
Responda apenas com base no contexto fornecido.

Contexto:
[Fonte 1] O prazo para solicitar reembolso é de 30 dias.
[Fonte 2] A solicitação deve incluir nota fiscal.

Pergunta:
Em quanto tempo devo solicitar um reembolso?
```

O builder organiza instruções, separa pergunta e fontes, identifica chunks, reduz ambiguidades e prepara mensagens para o `LanguageModel`.

### Language Model ou LLM

**O que é.** É o modelo capaz de receber mensagens e gerar texto em linguagem natural. Ele não é o modelo de embedding: uma função cria coordenadas; a outra escreve a resposta.

**Para que serve.** Os chunks recuperados são evidências brutas. O LLM organiza essas evidências numa resposta legível, seguindo as instruções do prompt.

**Como o ContextEngine usa.** Depois de montar o prompt, `RagPipeline` chama `LanguageModel::complete()` e cria `Answer`. O pacote inclui `OpenAILanguageModel`, `OllamaLanguageModel` e `GeminiLanguageModel`; todos são buffered, não streaming incremental.

### Tenant

**O que é.** Tenant identifica uma organização independente usando o mesmo sistema, como `empresa-a` e `empresa-b`.

**Para que serve.** Sistemas compartilhados precisam impedir que dados de uma empresa apareçam para outra. É como manter arquivos em salas separadas, mesmo dentro do mesmo prédio.

**Como o ContextEngine usa.** Tenant é obrigatório em documento, chunk, request de embedding, pergunta, busca e cache. `PgVectorStore` aplica o filtro no SQL; não depende de filtragem posterior em memória.

### Collection

**O que é.** Collection é uma divisão lógica de documentos dentro de um tenant, como `financeiro`, `suporte` ou `recursos-humanos`.

**Para que serve.** Permite pesquisar apenas uma área relevante. É como escolher a estante correta antes de pedir ao bibliotecário que procure um capítulo.

**Como o ContextEngine usa.** Documentos e chunks carregam collection; ela participa da identidade persistida e, quando configurada no `Retriever`, do filtro SQL. `TextFileLoader` usa `default`; outro valor exige loader próprio.

### Cache

**O que é.** Cache é armazenamento temporário de resultados já calculados.

**Para que serve.** Gerar o mesmo embedding ou resposta novamente pode consumir tempo e dinheiro. Cache é como guardar uma conta já resolvida para não recalculá-la. Ele acelera, mas não substitui o banco vetorial.

**Como o ContextEngine usa.** `CachedEmbeddingProvider` guarda embeddings por tenant, espaço e texto. `CachedLanguageModel`, desativado por padrão, pode guardar resposta por tenant, modelo, prompt e contexto. Falhas, valores inválidos e streaming não são cacheados. Qualquer implementação PSR-16 pode ser injetada.

### Fiber

**O que é.** Fiber é um mecanismo do PHP que permite pausar e retomar uma tarefa cooperativamente. Não é uma thread e não muda os contratos públicos.

**Para que serve.** Chamadas HTTP passam parte do tempo aguardando rede. Fibers permitem que outra chamada avance nesse intervalo, mantendo um limite para não sobrecarregar provider ou aplicação.

**Como o ContextEngine usa.** `FiberBatchEmbeddingExecutor` mantém uma janela de lotes em andamento. Ele pertence à infraestrutura; o usuário apenas escolhe `concurrency` e recebe resultados normais.

### Future

**O que é.** Future é um objeto interno que representa uma operação iniciada cujo resultado será obtido depois.

**Para que serve.** O executor precisa acompanhar cada chamada HTTP até poder aguardar sua conclusão. A analogia é um comprovante de pedido: ele representa algo solicitado, não o produto pronto.

**Como o ContextEngine usa.** O executor cria e aguarda Futures dentro de `Infrastructure`. Depois converte os resultados em `BatchEmbeddingResult`. Future nunca aparece em provider público, domínio, pipeline RAG ou retorno da API consumidora.

### Ordem cronológica: quando cada coisa acontece

```text
FASE 1 — quando um documento é adicionado ou atualizado
Loader → Document → Splitter → Chunks → Embedding Provider
       → Embeddings → Vector Store → PostgreSQL/pgvector

FASE 2 — quando o usuário faz uma pergunta
Question → Embedding Provider → embedding da pergunta
         → Retriever → busca vetorial → contexto
         → Prompt Builder → Language Model → Answer + fontes
```

O carregamento, chunking e armazenamento pertencem à ingestão. A busca, montagem do contexto e chamada ao LLM pertencem à pergunta. A pergunta recebe um embedding para poder ser comparada, mas não reingere os documentos.

## 🔗 Como todas as peças trabalham juntas

Os componentes acima formam dois processos separados no tempo.

### Fase 1 — Ingestão

**Diagrama conceitual:**

```text
Arquivo ou fonte de dados
        ↓
DocumentLoader → Document
        ↓
TextSplitter → Chunk[]
        ↓
BatchEmbeddingExecutor → EmbeddingProvider
        ↓                        ↓
        └──────────────── Embedding[]
                                 ↓
                            VectorStore
                                 ↓
                      PostgreSQL + pgvector
```

Essa fase acontece quando documentos são adicionados ou atualizados. Ela prepara conhecimento reutilizável: o loader entrega documentos, o splitter cria chunks, o executor organiza lotes, o provider produz embeddings e o store persiste tudo.

### Fase 2 — Consulta RAG

**Diagrama conceitual:**

```text
Question
    ↓
EmbeddingProvider → embedding da pergunta
    ↓
Retriever → VectorStore
    ↓
Chunks relevantes
    ↓
ContextPromptBuilder
    ↓
LanguageModel
    ↓
Answer + fontes
```

Essa fase acontece quando o usuário pergunta. O mesmo `EmbeddingProvider` usado nos chunks gera uma coordenada compatível para a pergunta. O retriever pesquisa o que já está armazenado, o prompt builder organiza as evidências e somente então o modelo de linguagem é chamado.

### O que não acontece durante uma pergunta

```text
❌ os arquivos não são lidos novamente;
❌ os documentos não são ingeridos novamente;
❌ todos os documentos não são enviados ao LLM;
❌ o modelo não aprende permanentemente o conteúdo;
❌ o embedding não é apresentado ao usuário;

✅ a pergunta recebe um embedding temporário;
✅ o banco procura chunks próximos e compatíveis;
✅ somente os chunks selecionados entram no contexto;
✅ a resposta pode incluir as fontes utilizadas.
```

A pergunta não é armazenada como documento pelo fluxo atual.

---

## 🧰 4. Antes de começar

### Obrigatório

- PHP `^8.4`;
- Composer;
- extensões `pdo`, `pdo_pgsql` e `sockets`;
- PostgreSQL com pgvector;
- banco e schema criados;
- acesso aos quatro repositórios Git Omegaalfa.

```bash
php -v
composer --version
php -m | grep -E 'PDO|pdo_pgsql|sockets'
```

### Escolha para embeddings

- Ollama: este guia usa `bge-m3`, com 1024 dimensões, e exige o serviço/modelo ativos.
- OpenAI: alternativa incluída que exige internet e chave; seu padrão usa 1.536 dimensões e requer outro schema.
- Gemini ou outro serviço: implementação própria de `EmbeddingProvider`, enquanto não houver adapter oficial.

Para a resposta final, estão incluídos `OpenAILanguageModel`, `OllamaLanguageModel` e `GeminiLanguageModel`. Qualquer outra integração pode ser usada se implementar `LanguageModel`. Redis, Docker, `omegaalfa/collection` e `omegaalfa/lazy-object` são opcionais.

## 🏗️ 5. O projeto de exemplo

Carregaremos `documents/politica-reembolso.txt` e perguntaremos “Em quanto tempo devo solicitar um reembolso?”.

```text
context-engine-example/
├── documents/politica-reembolso.txt
├── composer.json
├── schema.sql
└── example.php
```

## 📁 6. Criando o projeto

```bash
mkdir context-engine-example
cd context-engine-example
mkdir documents
```

Execute no local em que guarda projetos. O resultado é uma aplicação consumidora separada da biblioteca.

## 📦 7. Instalando a biblioteca

Em 29/07/2026, a busca não confirmou `omegaalfa/context-engine` no Packagist. O GitHub é público, mas o pacote e três dependências usam `dev-main`. Use esta instalação VCS, sem inventar uma versão estável:

```json
{
  "name": "exemplo/context-engine-example",
  "type": "project",
  "require": {
    "php": "^8.4",
    "omegaalfa/context-engine": "dev-main"
  },
  "repositories": [
    {"type": "vcs", "url": "https://github.com/omegaalfa/ContextEngine"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/query-builder"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/HttpClient"},
    {"type": "vcs", "url": "https://github.com/omegaalfa/FiberEventLoop"}
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

`require` declara o pacote. `repositories` informa onde encontrá-lo. `dev-main` é a branch em desenvolvimento; `minimum-stability` permite essa versão. Instale:

```bash
composer install
```

Espere `vendor/`, `composer.lock` e `vendor/autoload.php`. Erros de resolução geralmente indicam repositório irmão ausente.

## 🐘 8. Preparando PostgreSQL

```bash
createdb context_engine_example
psql -d context_engine_example -c 'CREATE EXTENSION IF NOT EXISTS vector;'
```

Salve em `schema.sql` e execute com `psql -d context_engine_example -f schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS context_chunks (
    chunk_id text NOT NULL,
    document_id text NOT NULL,
    tenant_id text NOT NULL,
    collection text NOT NULL,
    status text NOT NULL,
    content text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    metadata jsonb NOT NULL DEFAULT '{}',
    embedding vector(1024) NOT NULL,
    embedding_provider text NOT NULL,
    embedding_model text NOT NULL,
    embedding_dimensions integer NOT NULL CHECK (embedding_dimensions = 1024),
    embedding_revision text NOT NULL CHECK (embedding_revision <> ''),
    embedding_space_fingerprint text NOT NULL CHECK (embedding_space_fingerprint <> ''),
    CONSTRAINT context_chunks_identity PRIMARY KEY (
        tenant_id, collection, chunk_id, embedding_space_fingerprint
    )
);

CREATE INDEX IF NOT EXISTS context_chunks_scope_idx ON context_chunks
    (tenant_id, collection, status, embedding_space_fingerprint);
```

A chave composta impede duplicação da mesma versão no mesmo escopo. Texto e metadata permanecem ao lado do embedding.

> O schema oficial e o exemplo usam `bge-m3` com `vector(1024)`. Para outro modelo, confirme a dimensão e ajuste coluna, check, provider e estratégia de migração antes de ingerir dados.

## 🐳 9. Opção com Docker

O compose do repositório inicia pgvector em `localhost:54339` e Redis em `localhost:63809`:

```bash
cp .env.example .env
docker compose --env-file .env --profile integration up -d --wait
docker compose --env-file .env --profile integration ps
docker compose --env-file .env --profile integration down
```

Docker não é obrigatório. O compose monta o schema `vector(1024)` compatível com o BGE-M3. O SQL de inicialização só roda quando o volume é criado; um volume inicializado com schema anterior precisa ser recriado ou migrado conscientemente.

## 🤖 10. Escolhendo providers

O restante da engine não precisa saber qual fornecedor foi escolhido. Ingestão conhece `EmbeddingProvider`; RAG conhece `LanguageModel`; streaming, quando real, conhece `StreamingLanguageModel`.

```text
                    ┌─ OpenAI
EmbeddingProvider ──┼─ Ollama
                    ├─ Gemini (adapter próprio)
                    └─ qualquer implementação compatível

                    ┌─ OpenAI
LanguageModel ──────┼─ GeminiLanguageModel
                    └─ qualquer implementação compatível
```

### A — OpenAI

```bash
export OPENAI_API_KEY='sua-chave-aqui'
```

Não grave a chave em PHP ou Git. O provider usa `text-embedding-3-small`, 1.536 dimensões e `https://api.openai.com/v1` por padrão. O LLM padrão é `gpt-4.1-mini`; disponibilidade e preço devem ser conferidos na conta OpenAI.

### B — Ollama

O ContextEngine espera `http://127.0.0.1:11434` e chama `/api/embed`. Verificação geral:

```bash
curl http://127.0.0.1:11434/api/tags
```

```php
$embeddingProvider = new OllamaEmbeddingProvider(
    model: 'NOME_CONFIRMADO_DO_MODELO',
    dimensions: DIMENSAO_CONFIRMADA,
);
```

Nome e dimensão dependem do modelo instalado e não são determinados pelo pacote. Adapte o schema. Ollama cobre embeddings; para a resposta final, implemente `LanguageModel` ou use OpenAI.

### C — Gemini ou outro provider

O ContextEngine não possui adapter Gemini neste momento. Não existe namespace ou construtor Gemini oficial para copiar. Uma integração deve implementar um ou mais contratos, conforme as capacidades reais:

```php
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\StreamingLanguageModel;

function configureEngine(
    EmbeddingProvider $embeddings,
    LanguageModel $languageModel,
    ?StreamingLanguageModel $streaming = null,
): void {
    // A aplicação compõe as pipelines com contratos, não com um fornecedor fixo.
}
```

Para Gemini, o adapter da aplicação seria responsável por autenticação, endpoint, payload, modelo, dimensão, validação da resposta e `EmbeddingSpace`. Implemente `StreamingLanguageModel` somente se o transporte entregar deltas reais. Consulte o [guia de extensão](extension-guide.md).

---

## 🔌 11. Configurando as dependências

Cada peça abaixo participa de uma etapa do fluxo. Os objetos concretos OpenAI servem apenas como bootstrap executável com os adapters existentes. `IngestionPipeline`, `Retriever` e `RagPipeline` recebem contratos e continuam iguais com Gemini ou qualquer outro provider compatível.

### 11.1 Cliente HTTP e event loop

O cliente envia JSON aos providers; o event loop coordena as operações HTTP concorrentes. Quando um provider baseado em `AsyncHttpClient` é usado pelo `FiberBatchEmbeddingExecutor`, os dois componentes **devem receber a mesma instância** de `FiberEventLoop`.

```php
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;

$eventLoop = new FiberEventLoop();
$http = new AsyncHttpClient($eventLoop);
```

Resultado: um único scheduler coordena o cliente e, mais adiante, o executor. Criar loops independentes pode deixar `IngestionPipeline::ingest()` aguardando uma operação que pertence ao outro loop. O loop aparece somente na composição da infraestrutura; contratos, domínio e retornos continuam sem `Future`.

### 11.2 Provider de embeddings e espaço

```php
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;

$embeddingProvider = new OllamaEmbeddingProvider(
    model: 'bge-m3',
    dimensions: 1024,
    client: $http,
);
$space = $embeddingProvider->space();
```

O provider transforma texto em embedding. `space()` retorna a identidade efetivamente usada; não construa uma identidade diferente em paralelo.

### 11.3 Conexão e QueryBuilder

```php
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

$settings = new DatabaseSettings(
    driver: 'pgsql',
    host: $databaseHost,
    database: $databaseName,
    port: $databasePort,
    username: $databaseUser,
    password: $databasePassword,
);
$connection = new PDOConnection($settings);
$queryBuilder = new QueryBuilder($connection);
```

`DatabaseSettings` descreve o acesso; `PDOConnection` abre a conexão; `QueryBuilder` prepara SQL.

### 11.4 Store, splitter e executor

```php
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;

$vectorStore = new PgVectorStore($queryBuilder);
$splitter = new RecursiveTextSplitter(chunkSize: 500, overlap: 75);
$executor = new FiberBatchEmbeddingExecutor(
    loop: $eventLoop,
    concurrency: 4,
);
```

O store persiste; o splitter divide; o executor mantém no máximo quatro lotes HTTP em andamento e entrega resultados para persistência serial.

### 11.5 Pipeline de ingestão

```php
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;

$ingestion = new IngestionPipeline(
    splitter: $splitter,
    embeddings: $embeddingProvider,
    store: $vectorStore,
    batchSize: 16,
    executor: $executor,
);
```

### 11.6 Retriever e política

```php
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;

$retriever = new Retriever(
    embeddings: $embeddingProvider,
    store: $vectorStore,
    policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
    collection: 'default',
);
```

Ele gera embedding da pergunta e recupera até cinco chunks compatíveis da collection.

### 11.7 Prompt, modelo e RAG

```php
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\RagPipeline;

$promptBuilder = new ContextPromptBuilder();
$languageModel = new OpenAILanguageModel(
    apiKey: $openAiKey,
    model: 'gpt-4.1-mini',
    client: $http,
);
$rag = new RagPipeline($retriever, $promptBuilder, $languageModel);
```

O modelo retorna resposta completa e não implementa streaming incremental.

## 🧭 12. `EmbeddingSpace` com calma

```php
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;

$space = new EmbeddingSpace(
    provider: 'ollama',
    model: 'bge-m3',
    dimensions: 1024,
    revision: '1',
    parameters: [],
);
echo $space->fingerprint();
```

Imagine que cada modelo usa um mapa. Provider identifica quem fez o mapa; model identifica o modelo; dimensions, o tamanho das coordenadas; revision, uma mudança controlada; parameters, opções semanticamente relevantes; fingerprint, a etiqueta calculada de tudo isso.

Não inclua chave, endpoint ou timeout em `parameters`: não alteram o significado do vetor. Se `dimensions` divergir do modelo ou de `vector(n)`, a criação do `Embedding` ou o banco falhará.

---

## 📝 13. Documento de exemplo

Salve em `documents/politica-reembolso.txt`:

```text
Política de reembolso

O colaborador pode solicitar reembolso de despesas profissionais previamente autorizadas. A solicitação deve ser enviada em até 30 dias corridos após a data da despesa.

O pedido deve incluir a nota fiscal legível, a justificativa da despesa e a identificação do centro de custo. Solicitações sem comprovante podem ser devolvidas para correção.

Depois da aprovação do gestor, o pagamento é processado na folha seguinte. Dúvidas devem ser encaminhadas ao setor financeiro.
```

As linhas em branco fazem o loader produzir um `Document` por bloco não vazio.

## 🚪 14. Carregando o documento

```php
use Omegaalfa\ContextEngine\Loader\TextFileLoader;

$loader = new TextFileLoader(
    path: __DIR__ . '/documents/politica-reembolso.txt',
    tenantId: 'empresa-a',
);
```

O loader aceita apenas caminho e tenant. Ele cria ID como SHA-256 de caminho + índice, metadata `source`, collection `default` e status `active`.

> ID, collection, status e metadata personalizados exigem um `DocumentLoader` próprio que produza objetos `Document`. Não existem esses parâmetros no `TextFileLoader` atual.

## ✂️ 15. Dividindo o documento

`chunkSize: 500` limita o trecho a no máximo 500 caracteres. `overlap: 75` repete exatamente os 75 caracteres finais no começo do trecho seguinte para evitar cortar uma ideia exatamente na divisão. O avanço por offsets garante que nenhum conteúdo normalizado fique entre duas janelas sem ser incluído.

Chunks muito pequenos perdem contexto e aumentam chamadas. Chunks muito grandes misturam assuntos e ocupam mais prompt. O splitter usa generator, produzindo chunks conforme o consumo.

## ⚙️ 16. Fazendo a ingestão

```php
$report = $ingestion->ingest($loader);

printf(
    "Completa: %s | chunks salvos: %d | lotes salvos: %d\n",
    $report->complete ? 'sim' : 'não',
    $report->chunksPersisted,
    $report->batchesPersisted,
);
```

`ingest()` lê, divide, agrupa, envia ao provider, valida, persiste e devolve `IngestionReport`. Campos reais:

- `batchesPlanned`, `batchesStarted`, `batchesCompleted`;
- `batchesPersisted`, `batchesDiscarded`;
- `chunksProduced`, `chunksSent`, `chunksPersisted`;
- `failure` (código e mensagem segura), `affectedBatchSequences`, `complete`.

Uma saída possível é `Completa: sim | chunks salvos: 3 | lotes salvos: 3`; números dependem do conteúdo.

## 🗄️ 17. O que foi salvo

```sql
SELECT tenant_id, collection, chunk_id, document_id, position, status,
       left(content, 100) AS preview,
       embedding_provider, embedding_model, embedding_dimensions,
       embedding_revision, embedding_space_fingerprint
FROM context_chunks
ORDER BY tenant_id, collection, document_id, position;
```

`content` mantém o texto; `embedding`, o vetor; `metadata`, JSON; as colunas `embedding_*`, a identidade do espaço.

---

## 💬 18. Fazendo uma pergunta

```php
$answer = $rag->ask(
    'Em quanto tempo devo solicitar um reembolso?',
    'empresa-a',
);
echo $answer->content . PHP_EOL;
```

A pergunta vira embedding; o banco aplica tenant, collection, status e espaço; os chunks viram contexto; o `LanguageModel` configurado redige a resposta. Uma saída ilustrativa é “A solicitação deve ser enviada em até 30 dias corridos”. A redação pode variar conforme o provider.

## 🔎 19. Entendendo as fontes

```php
foreach ($answer->sources as $source) {
    printf(
        "[%s] distância=%.4f documento=%s\n%s\n",
        $source->chunk->id,
        $source->distance,
        $source->chunk->documentId,
        $source->chunk->content,
    );
}
```

Cada fonte é `VectorSearchResult`, contendo `chunk` e `distance`. O chunk expõe ID, documento, tenant, conteúdo, posição, metadata, collection e status.

## 🔐 20. Tenant e collection

```text
empresa-a / recursos-humanos
empresa-b / recursos-humanos
```

Collections podem ter o mesmo nome, mas tenants diferentes permanecem isolados. `Question` exige tenant e `PgVectorStore` o filtra no SQL. Dentro de um tenant, `recursos-humanos`, `financeiro` e `suporte` separam assuntos.

Como `TextFileLoader` usa `default`, collections personalizadas exigem loader próprio com `new Document(..., collection: 'financeiro')`.

---

## ⚡ 21. Utilizando cache

No primeiro teste, ignore cache. Primeiro confirme banco, ingestão e resposta.

```php
use Omegaalfa\ContextEngine\Cache\CachedEmbeddingProvider;

$embeddingProvider = new CachedEmbeddingProvider(
    provider: $realEmbeddingProvider,
    cache: $psr16Cache,
    ttl: 3600,
);
```

O cache precisa implementar `Psr\SimpleCache\CacheInterface`. A chave de embedding considera tenant, provider, modelo, dimensão, fingerprint e hash exato do texto; duplicatas no lote não repetem chamada.

```php
use Omegaalfa\ContextEngine\Cache\CachedLanguageModel;

$languageModel = new CachedLanguageModel(
    model: $realLanguageModel,
    cache: $psr16Cache,
    tenantId: 'empresa-a',
    promptVersion: $promptBuilder->version,
    enabled: true,
    ttl: 600,
);
```

Cache de resposta é desativado por padrão porque LLMs podem variar. A chave inclui tenant, identidade do modelo, mensagens/contexto e versão do prompt. Falhas e streaming não são cacheados.

O pacote não inclui adaptador Redis PSR-16. Compose e teste confirmam apenas Redis autenticado/persistente; a aplicação escolhe uma implementação PSR-16. Por isso não é inventada aqui uma classe Redis específica.

## 🔄 22. Atualizando um documento

O store usa **upsert**: insere se não existe e atualiza se já existe. A identidade é:

```text
tenant + collection + chunk_id + embedding_space_fingerprint
```

Reingerir a mesma identidade atualiza conteúdo, metadata, status e vetor, sem cópia. Isso é **idempotência**: repetir não duplica a mesma versão.

O ID do loader depende de caminho + índice do bloco. Reorganizar parágrafos pode criar IDs diferentes; um loader próprio pode adotar IDs do domínio.

## 🧬 23. Alterando o modelo de embedding

Mudar provider, modelo, dimensão, revisão ou parâmetro semântico muda o fingerprint. Versões antigas e novas podem coexistir e não se misturam na busca.

Uma coluna `vector(1024)` rejeita outra dimensão. Modelos com dimensões distintas exigem schema/tabelas compatíveis; o fingerprint resolve identidade lógica, não muda o tipo físico da coluna.

## 🚨 24. Tratamento de erros

| Sintoma | Causa provável | Como investigar e corrigir |
|---|---|---|
| Conexão recusada | PostgreSQL parado/host incorreto | Use `pg_isready`; revise host/porta. |
| `type vector does not exist` | pgvector ausente | Execute `CREATE EXTENSION vector`. |
| Relação inexistente | schema não criado | Rode `psql -f schema.sql`. |
| HTTP 401 | API key inválida | Revise variável sem imprimir o segredo; provider pode lançar `ProviderException`. |
| Ollama recusado | serviço/endpoint indisponível | Teste `/api/tags`. |
| Modelo não encontrado | nome não instalado | Confirme no serviço externo. |
| Dimensão incompatível | provider/espaço/tabela discordam | Compare `space()->dimensions` e `vector(n)`; pode ocorrer `InvalidEmbeddingException` ou erro SQL. |
| `Unable to open` | arquivo/caminho/permissão | Corrija caminho; loader lança `RuntimeException`. |
| Resposta sem dados | payload externo inesperado | Investigue status de forma segura; `ProviderException`. |
| Lote com tamanho diferente | provider violou cardinalidade | Executor falha; `IngestionException` preserva relatório parcial. |
| Tenant vazio | escopo obrigatório ausente | Forneça valor não vazio; `InvalidArgumentException`. |
| Nenhuma fonte | banco vazio ou filtro/espaço errado | Confira tenant, collection, status, fingerprint e registros. |

```php
use Omegaalfa\ContextEngine\Exception\IngestionException;

try {
    $report = $ingestion->ingest($loader);
} catch (IngestionException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Chunks já salvos: ' . $error->partialReport->chunksPersisted . PHP_EOL);
}
```

Lotes persistidos permanecem duráveis; uma nova execução pode retomá-los com upsert idempotente.

## ✅ 25. Como saber se funciona

```text
[ ] PHP e extensões disponíveis
[ ] PostgreSQL responde
[ ] pgvector instalada
[ ] context_chunks existe
[ ] provider responde
[ ] ingestão gravou chunks
[ ] retrieval retornou fontes
[ ] LLM gerou resposta
```

```bash
php -v
pg_isready -h 127.0.0.1 -d context_engine_example
psql -d context_engine_example -c "SELECT extversion FROM pg_extension WHERE extname='vector';"
psql -d context_engine_example -c "SELECT count(*) FROM context_chunks;"
php example.php
```

Nunca confira credenciais imprimindo-as em logs.

---

## 🚀 26. Exemplo completo final

### Primeiro: a composição de produção é neutra

Em produção, mantenha a montagem principal dependente dos contratos. A função abaixo não conhece OpenAI, Gemini, Ollama ou SDK externo:

```php
use Omegaalfa\ContextEngine\Contract\EmbeddingProvider;
use Omegaalfa\ContextEngine\Contract\LanguageModel;
use Omegaalfa\ContextEngine\Contract\BatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\QueryBuilder;

/** @return array{ingestion: IngestionPipeline, rag: RagPipeline} */
function buildContextEngine(
    EmbeddingProvider $embeddings,
    LanguageModel $languageModel,
    QueryBuilder $queryBuilder,
    BatchEmbeddingExecutor $batchExecutor,
): array {
    $store = new PgVectorStore($queryBuilder);
    $ingestion = new IngestionPipeline(
        splitter: new RecursiveTextSplitter(chunkSize: 500, overlap: 75),
        embeddings: $embeddings,
        store: $store,
        batchSize: 16,
        executor: $batchExecutor,
    );
    $rag = new RagPipeline(
        retriever: new Retriever(
            embeddings: $embeddings,
            store: $store,
            policy: new RetrievalPolicy(
                limit: 5,
                metric: VectorMetric::COSINE,
            ),
            collection: 'default',
        ),
        prompts: new ContextPromptBuilder(),
        model: $languageModel,
    );

    return ['ingestion' => $ingestion, 'rag' => $rag];
}
```

O bootstrap da aplicação constrói `$batchExecutor` com o mesmo loop usado pelo cliente HTTP do provider e o entrega à função. Assim, essa fábrica permanece neutra quanto ao transporte e ao mecanismo de concorrência.

Se amanhã existirem `$geminiEmbeddings` e `$geminiLanguageModel` implementando os contratos, a chamada será `buildContextEngine($geminiEmbeddings, $geminiLanguageModel, $queryBuilder, $batchExecutor)`. A engine não muda. Os adapters concretos pertencem ao bootstrap ou container de dependências da aplicação.

### Depois: um bootstrap executável com os adapters disponíveis

O arquivo a seguir usa OpenAI como exemplo remoto. O pacote também inclui `OllamaLanguageModel` para respostas locais buffered; ambos implementam o mesmo contrato e podem ser trocados no bootstrap.

Configure o ambiente:

```bash
export OPENAI_API_KEY='sua-chave'
export DB_HOST='127.0.0.1'
export DB_PORT='5432'
export DB_DATABASE='context_engine_example'
export DB_USERNAME='context_engine'
export DB_PASSWORD='senha-segura'
```

Salve como `example.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\ContextEngine\Exception\IngestionException;
use Omegaalfa\ContextEngine\Infrastructure\Ingestion\FiberBatchEmbeddingExecutor;
use Omegaalfa\ContextEngine\Ingestion\IngestionPipeline;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Prompt\ContextPromptBuilder;
use Omegaalfa\ContextEngine\Provider\Ollama\OllamaEmbeddingProvider;
use Omegaalfa\ContextEngine\Provider\OpenAI\OpenAILanguageModel;
use Omegaalfa\ContextEngine\Rag\RagPipeline;
use Omegaalfa\ContextEngine\Retrieval\Retriever;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;
use Omegaalfa\ContextEngine\Retrieval\VectorMetric;
use Omegaalfa\ContextEngine\Splitter\RecursiveTextSplitter;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;

$env = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Defina a variável {$name}.");
    }
    return $value;
};

try {
    $key = $env('OPENAI_API_KEY');
    $eventLoop = new FiberEventLoop();
    $http = new AsyncHttpClient($eventLoop);
    $embeddings = new OllamaEmbeddingProvider(
        model: 'bge-m3',
        dimensions: 1024,
        client: $http,
    );

    $settings = new DatabaseSettings(
        driver: 'pgsql',
        host: $env('DB_HOST'),
        database: $env('DB_DATABASE'),
        port: (int) $env('DB_PORT'),
        username: $env('DB_USERNAME'),
        password: $env('DB_PASSWORD'),
    );
    $store = new PgVectorStore(new QueryBuilder(new PDOConnection($settings)));
    $batchExecutor = new FiberBatchEmbeddingExecutor(
        loop: $eventLoop,
        concurrency: 4,
    );

    $ingestion = new IngestionPipeline(
        splitter: new RecursiveTextSplitter(chunkSize: 500, overlap: 75),
        embeddings: $embeddings,
        store: $store,
        batchSize: 16,
        executor: $batchExecutor,
    );
    $report = $ingestion->ingest(new TextFileLoader(
        __DIR__ . '/documents/politica-reembolso.txt',
        'empresa-a',
    ));
    printf("Ingestão completa; %d chunks persistidos.\n", $report->chunksPersisted);

    $retriever = new Retriever(
        embeddings: $embeddings,
        store: $store,
        policy: new RetrievalPolicy(limit: 5, metric: VectorMetric::COSINE),
        collection: 'default',
    );
    $rag = new RagPipeline(
        retriever: $retriever,
        prompts: new ContextPromptBuilder(),
        model: new OpenAILanguageModel(
            apiKey: $key,
            model: 'gpt-4.1-mini',
            client: $http,
        ),
    );

    $answer = $rag->ask(
        'Em quanto tempo devo solicitar um reembolso?',
        'empresa-a',
    );
    echo "\nResposta:\n{$answer->content}\n\nFontes:\n";
    foreach ($answer->sources as $source) {
        printf(
            "- chunk=%s distância=%.4f conteúdo=%s\n",
            $source->chunk->id,
            $source->distance,
            $source->chunk->content,
        );
    }
} catch (IngestionException $error) {
    fwrite(STDERR, "Falha de ingestão: {$error->getMessage()}\n");
    fwrite(STDERR, "Chunks persistidos: {$error->partialReport->chunksPersisted}\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Falha: {$error->getMessage()}\n");
    exit(1);
}
```

Execute `php example.php`. Espere linhas no banco, resposta e fontes. Sem decorator de cache, embeddings são recalculados nas execuções seguintes, embora o upsert evite duplicatas.

## ⚖️ 27. O que faz e o que não faz

| O ContextEngine faz | A aplicação precisa fazer |
|---|---|
| Lê `.txt` | Fornecer arquivos/loaders adicionais |
| Divide documentos | Escolher chunk e overlap |
| Gera embeddings | Configurar um `EmbeddingProvider` (OpenAI, Ollama, Gemini próprio etc.) |
| Limita concorrência HTTP | Escolher limite adequado |
| Salva e busca no pgvector | Provisionar banco e schema |
| Isola tenant/collection | Autenticar e escolher tenant |
| Monta prompt/fontes | Definir regras da aplicação |
| Gera resposta pelo provider injetado | Configurar `LanguageModel`, credencial e custo |
| Aceita PSR-16 | Configurar backend de cache |
| Expõe classes PHP | Criar API, CLI ou tela |

## 🚧 28. Limitações atuais

- sem interface web, API HTTP ou CLI de aplicação;
- loader nativo apenas para texto; PDF/HTML exigem implementação;
- sem busca híbrida ou reranking;
- providers atuais sem streaming incremental real;
- extensão, tabela e índices não são criados em runtime;
- providers externos, como OpenAI ou Gemini, dependem de rede/credencial; Ollama depende de serviço/modelo;
- Ollama possui adapters de embedding e linguagem; Gemini possui adapter de linguagem, mas embeddings Gemini ainda exigem implementação própria;
- coluna `vector(n)` possui dimensão física fixa;
- nenhum adaptador Redis PSR-16 incluído.

## 🪜 29. Próximos passos do iniciante

1. Teste um arquivo e uma collection.
2. Inspecione chunks e fontes.
3. Adicione documentos gradualmente.
4. Crie loader com IDs/collections próprios.
5. Exponha uma pequena API HTTP.
6. Adicione autenticação e tenant derivado do usuário.
7. Configure cache depois de medir repetição.
8. Monitore latência, erros, tokens, custo e qualidade.

## ✨ 30. Resumo visual final

```text
Preparar: PHP + Composer + PostgreSQL + pgvector + provider
Ensinar:  carregar + dividir + gerar embeddings + salvar
Perguntar: embedding da pergunta + buscar chunks + montar contexto
Responder: contexto para o modelo + resposta + fontes
```

### O fluxo em dez linhas

1. A aplicação fornece arquivo e tenant.
2. O loader produz documentos.
3. O splitter produz chunks.
4. O batcher agrupa chunks.
5. O provider cria embeddings.
6. O store salva texto, vetor e espaço.
7. A pergunta recebe embedding no mesmo espaço.
8. O retriever busca no escopo correto.
9. O prompt builder organiza pergunta e fontes.
10. O modelo gera `Answer` com conteúdo e fontes.

---

## 📖 Glossário resumido

| Termo | Resumo |
|---|---|
| RAG | Responder após consultar documentos. |
| Ingestão | Preparar e armazenar documentos. |
| Chunk | Parte pequena do documento. |
| Embedding | Números que representam significado. |
| Espaço vetorial | Identidade do mapa dos embeddings. |
| Retrieval | Recuperação dos chunks relevantes. |
| Tenant | Organização isolada. |
| Collection | Categoria dentro do tenant. |
| Provider | Adaptador para serviço. |
| LLM | Modelo que escreve a resposta. |

## ☑️ Checklist de instalação

```text
[ ] PHP 8.4 e extensões
[ ] Composer
[ ] Repositórios VCS e dependências
[ ] PostgreSQL e pgvector
[ ] Schema com dimensão correta
[ ] Documento de exemplo
[ ] Um EmbeddingProvider configurado
[ ] Um LanguageModel configurado
[ ] Variáveis de banco
[ ] example.php executado
```

## 🔗 Links relevantes

- [ContextEngine](https://github.com/omegaalfa/ContextEngine)
- [README](https://github.com/omegaalfa/ContextEngine/blob/main/README.md)
- [composer.json](https://github.com/omegaalfa/ContextEngine/blob/main/composer.json)
- [Schema de integração](https://github.com/omegaalfa/ContextEngine/blob/main/tests/Integration/Fixtures/postgresql/schema.sql)
- [Docker Compose](https://github.com/omegaalfa/ContextEngine/blob/main/docker-compose.yml)
- [Código](https://github.com/omegaalfa/ContextEngine/tree/main/src) e [testes](https://github.com/omegaalfa/ContextEngine/tree/main/tests)
- [QueryBuilder](https://github.com/omegaalfa/query-builder), [HttpClient](https://github.com/omegaalfa/HttpClient) e [FiberEventLoop](https://github.com/omegaalfa/FiberEventLoop)

## 🧾 Arquivos consultados

`README.md`, `composer.json`, `composer.lock`, `.env.example`, `docker-compose.yml`, todos os contratos e módulos em `src/`, schema de integração, testes PgVector/Redis e testes unitários de arquitetura, ingestão, cache, retrieval e versionamento.

## 🔬 Pontos não confirmados

- publicação no Packagist: não localizada em 29/07/2026;
- tag estável: o pacote atual exige `dev-main`;
- dimensão de modelos Ollama/Gemini: depende do modelo e adapter escolhidos;
- disponibilidade/preço futuro de OpenAI, Gemini ou outro serviço: depende do fornecedor;
- adaptador Redis PSR-16 recomendado: o pacote não escolhe um;
- adequação a carga de produção específica: exige validação na aplicação real.
