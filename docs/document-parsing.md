# 🧩 Parsing de documentos e chunking estrutural

> **Objetivo:** transformar documentos de formatos diferentes em uma árvore lógica comum antes de gerar chunks, embeddings e registros no vector store.

Este guia explica a arquitetura de ingestão estrutural do ContextEngine, os formatos suportados, os metadados produzidos e os pontos de extensão. Para o ciclo de persistência, batching e tratamento de falhas, consulte também [Ingestão](ingestion.md).

## 🗺️ Visão geral

Antes desta arquitetura, o chunking operava principalmente sobre texto linear. Agora, o documento passa primeiro por uma etapa de interpretação estrutural:

```text
┌──────────────────┐
│ DocumentLoader   │  lê arquivo, PDF, API ou outra fonte
└────────┬─────────┘
         ↓
┌──────────────────┐
│ Document         │  conteúdo bruto + tenant + collection + metadata
└────────┬─────────┘
         ↓
┌──────────────────┐
│ DocumentParser   │  interpreta o formato; não cria chunks
└────────┬─────────┘
         ↓
┌──────────────────┐
│ DocumentNode     │  árvore lógica, ordenada e imutável
└────────┬─────────┘
         ↓
┌──────────────────┐
│ ChunkBuilder     │  preserva blocos, contexto e hierarquia
└────────┬─────────┘
         ↓
┌──────────────────┐
│ Chunk[]          │  conteúdo + origem + posição + caminho estrutural
└────────┬─────────┘
         ↓
┌──────────────────┐
│ EmbeddingProvider│
└────────┬─────────┘
         ↓
┌──────────────────┐
│ VectorStore      │
└──────────────────┘
```

### Responsabilidades

| Etapa | Faz | Não faz |
|---|---|---|
| `DocumentLoader` | lê a fonte e cria `Document` | interpretar Markdown ou gerar embeddings |
| `DocumentParser` | converte conteúdo bruto em nós lógicos | decidir tamanho de chunk |
| `DocumentNode` | representa ordem, hierarquia, conteúdo e metadata | executar I/O ou alterar estado |
| `ChunkBuilder` | percorre a árvore e cria chunks consistentes | ler arquivos ou chamar providers |
| `ChunkingStrategy` | define quando um chunk atingiu seu limite | conhecer tenant, banco ou formato do arquivo |
| `EmbeddingProvider` | transforma conteúdo em vetores | interpretar estrutura documental |
| `VectorStore` | persiste e recupera versões vetoriais | fazer parsing ou chunking |

## 🌳 Modelo estrutural comum

Todos os parsers produzem um `DocumentNode`. Seus filhos implementam o contrato `Node` e formam uma árvore imutável.

```text
DocumentNode
├── HeadingNode     "Manual da API"
├── ParagraphNode   "Este documento descreve..."
├── SectionNode     "Autenticação"
│   ├── ParagraphNode
│   ├── CodeBlockNode  language=php
│   └── ListNode
├── SectionNode     "Respostas"
│   ├── TableNode
│   └── QuoteNode
└── SectionNode     "Erros"
```

### Tipos de nó

| Nó | Representa | Exemplos de metadata |
|---|---|---|
| `DocumentNode` | raiz do documento | formato, origem, versão |
| `SectionNode` | agrupamento hierárquico | segmento do caminho |
| `HeadingNode` | título ou subtítulo | `level` |
| `ParagraphNode` | texto corrido | chave de origem em JSON/XML |
| `CodeBlockNode` | código-fonte ou bloco cercado | `language`, `symbol_type` |
| `TableNode` | tabela preservada como unidade | informações fornecidas pelo parser |
| `ListNode` | lista ordenada ou não ordenada | `ordered` |
| `QuoteNode` | citação ou bloco destacado | metadata da origem |
| `FigureTextNode` | texto residual de figura | motivo e confiança da classificação |
| `DiagramTextNode` | rótulos ou valores dispersos de diagrama | motivo e posição original |
| `UnknownNode` | bloco suspeito sem categoria segura | heurística aplicada |

Cada nó expõe:

```php
interface Node
{
    public function type(): string;
    public function content(): string;

    /** @return list<Node> */
    public function children(): array;

    /** @return array<string, scalar|null> */
    public function metadata(): array;
}
```

Os arrays são tratados como listas ordenadas. Parsers não podem reorganizar blocos, misturar responsabilidades ou depender de IA.

## 📄 Formatos suportados

O `ParserRegistry` seleciona o parser usando esta precedência:

```text
metadata.format
      ↓ ausente
metadata.type
      ↓ ausente
extensão de metadata.source
      ↓ desconhecida
PlainTextParser
```

| Formato | Parser | Estruturas reconhecidas |
|---|---|---|
| Texto simples | `PlainTextParser` | parágrafos separados por linhas vazias |
| Markdown | `MarkdownParser` | headings, parágrafos, listas, citações e blocos de código |
| HTML | `HtmlParser` | `h1`–`h6`, `p`, `pre`, `code`, `blockquote`, listas e tabelas |
| JSON | `JsonParser` | objetos e arrays como seções hierárquicas; escalares como parágrafos |
| XML | `XmlParser` | elementos como seções aninhadas e conteúdo textual como parágrafos |
| PHP | `PhpParser` | classes, interfaces, traits, enums, funções e métodos quando detectáveis |
| PDF | `PdfParser` | headings, parágrafos, listas, tabelas e código por heurísticas determinísticas; páginas como metadata |

> [!IMPORTANT]
> `PhpParser` faz ingestão estrutural consciente de código com `token_get_all()`. Isso **não é uma AST** nem Code Intelligence: relações entre símbolos, chamadas, herança, implementações, FQCN e grafo de dependências não são resolvidos.

No PDF, “tabela” significa detecção estrutural e preservação do texto disponível. Não significa reconstrução perfeita de células quando o layout foi perdido na extração.

### Exemplo de seleção

```php
use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;

$document = new Document(
    id: 'manual-api-v3',
    tenantId: 'tenant-42',
    content: "# Autenticação\n\nUse um token Bearer.",
    metadata: [
        'format' => 'markdown',
        'source' => '/docs/manual-api.md',
        'version' => '3',
    ],
    collection: 'developer-docs',
);

$registry = new ParserRegistry();
$parser = $registry->parserFor($document);
$tree = $parser->parse($document);
```

> **Importante:** metadata explícita vence a extensão. Isso permite processar conteúdo Markdown vindo de banco, fila ou API, mesmo quando não existe arquivo físico.

### PDFs não são divididos por página

O `PdfDocumentLoader` preserva marcadores de página no conteúdo extraído. O `PdfParser` usa esses marcadores apenas para atribuir `page_start` e `page_end` aos nós. O `ChunkBuilder` pode combinar conteúdo de páginas consecutivas quando pertence à mesma unidade lógica.

```text
Página 102                     Página 103
┌────────────────────┐         ┌────────────────────┐
│ Capítulo 5         │         │ continuação        │
│ explicação inicial │────────→│ da explicação      │
└────────────────────┘         └────────────────────┘
             ↓ parsing + chunking estrutural
┌───────────────────────────────────────────────────┐
│ Chunk: Capítulo 5                                 │
│ page_start: 102                                   │
│ page_end: 103                                     │
└───────────────────────────────────────────────────┘
```

Para livros, configure o loader para produzir um documento lógico único. O exemplo executável usa `pagesPerDocument: PHP_INT_MAX`; headings e limites da estratégia determinam os chunks, não janelas físicas de páginas.

## 🧹 Detecção de ruído estrutural

PDFs não armazenam necessariamente parágrafos, headings ou tabelas. Muitas vezes guardam apenas caracteres posicionados visualmente. Durante a extração, diagramas e figuras podem virar blocos como:

```text
Um      B                     B     D

0    0       0               0     0     0

A
B
C
D
```

O `PdfParser` executa `StructuralNoisePolicy` antes de tentar classificar um bloco como heading, tabela ou parágrafo.

```text
bloco extraído
      ↓
código ou lista válida? ── sim ─→ preservar
      ↓ não
StructuralNoisePolicy
      ├── conteúdo natural ──────→ parsing normal
      ├── FigureTextNode ────────→ diagnóstico
      ├── DiagramTextNode ───────→ diagnóstico
      └── UnknownNode ───────────→ diagnóstico
```

### Heurísticas

| Heurística | Sinal observado |
|---|---|
| caracteres alfabéticos mínimos | blocos sem linguagem natural |
| proporção mínima de texto | excesso de números e símbolos |
| grupos de espaços consecutivos | colunas visuais e rótulos dispersos |
| proporção de tokens isolados | sequências como `A B C D` |
| linhas de um único caractere | rótulos verticais de figuras |
| bloco exclusivamente numérico | eixos, matrizes e resíduos gráficos |
| densidade de palavras naturais | diferença entre frase curta e layout fragmentado |

As decisões são determinísticas, locais e não usam IA, OCR, rede ou estado externo.

### Preservação diagnóstica

Por padrão, blocos com alta confiança de ruído não são apagados da árvore. Eles recebem um nó específico e metadata diagnóstica:

```php
[
    'structural_noise' => true,
    'exclude_from_retrieval' => true,
    'noise_reason' => 'isolated_token_sequence',
    'noise_confidence' => 0.98,
    'source_position' => 42,
    'page_start' => 234,
    'page_end' => 234,
]
```

O `ChunkBuilder` ignora nós com `exclude_from_retrieval: true`. Assim, eles permanecem disponíveis para inspeção do parser, mas não geram embeddings nem chunks independentes.

Código-fonte e listas reconhecidas são protegidos antes da política para evitar perda de estruturas legítimas com símbolos ou linhas curtas.

### Configuração

Os defaults são conservadores:

```php
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Ingestion\Parser\ParserRegistry;
use Omegaalfa\ContextEngine\Ingestion\Parser\StructuralNoisePolicy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$noisePolicy = new StructuralNoisePolicy(
    enabled: true,
    minimumAlphabeticCharacters: 2,
    minimumTextRatio: 0.25,
    minimumBlockLength: 3,
    maximumIsolatedTokenRatio: 0.60,
    maximumConsecutiveSpaceGroups: 2,
);

$splitter = new StructuralTextSplitter(
    strategy: new CharacterLimitStrategy(1_200),
    parsers: new ParserRegistry($noisePolicy),
);
```

Para auditoria ou comparação, desabilite a filtragem sem trocar o parser:

```php
$policy = new StructuralNoisePolicy(enabled: false);
```

Os parâmetros participam do fingerprint do splitter. Alterar a política produz uma nova identidade de ingestão e evita misturar versões geradas com heurísticas diferentes.

### Limitações

- diagramas com frases completas podem parecer parágrafos legítimos;
- tabelas muito curtas podem ser classificadas como diagrama;
- fórmulas matemáticas sem descrição textual podem ser excluídas do retrieval;
- PDFs com ordem textual incorreta continuam exigindo um extrator melhor;
- a política não interpreta imagens nem reconstrói relações espaciais.

Quando houver dúvida, prefira defaults conservadores e inspecione `noise_reason`, `noise_confidence`, página e posição antes de tornar a filtragem mais agressiva.

## ✂️ Como o ChunkBuilder trabalha

O `ChunkBuilder` percorre a árvore em ordem de origem e mantém o caminho hierárquico ativo. Ele tenta combinar blocos completos enquanto a estratégia permite.

```text
Heading: "Autenticação"
Paragraph: "A API usa Bearer Token."
CodeBlock: "Authorization: Bearer ..."
Heading: "Erros"
Paragraph: "Tokens expirados retornam 401."

                    ↓ limite de 120 caracteres

Chunk 0
├── heading_parent: Autenticação
├── block_type: heading
└── Autenticação + parágrafo

Chunk 1
├── heading_parent: Autenticação
├── block_type: code
└── bloco de código completo

Chunk 2
├── heading_parent: Erros
├── block_type: heading
└── Erros + parágrafo
```

### Regras de corte

1. preserva a ordem original;
2. prefere limites naturais entre nós;
3. mantém blocos de código, listas e tabelas inteiros quando couberem;
4. combina blocos pequenos para evitar chunks fragmentados;
5. divide um bloco internamente somente quando ele excede sozinho o limite;
6. gera posições sequenciais e IDs determinísticos.

## ⚙️ Estratégias de chunking

As estratégias implementam `ChunkingStrategy`:

```php
interface ChunkingStrategy
{
    public function fingerprint(): string;
    public function fits(string $content, int $blockCount): bool;

    /** @return list<string> */
    public function split(string $content): array;
}
```

### Por caracteres

```php
use Omegaalfa\ContextEngine\Ingestion\Chunking\CharacterLimitStrategy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$splitter = new StructuralTextSplitter(
    new CharacterLimitStrategy(limit: 1_200),
);
```

`CharacterLimitStrategy` mede caracteres UTF-8. Ao dividir um bloco grande, tenta cortar em quebra de linha ou espaço antes de usar o limite rígido.

### Por tokens estimados

```php
use Omegaalfa\ContextEngine\Ingestion\Chunking\TokenLimitStrategy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$splitter = new StructuralTextSplitter(
    new TokenLimitStrategy(limit: 256),
);
```

Por padrão, `HeuristicTokenEstimator` estima um token a cada quatro caracteres. A estimativa é rápida, local e determinística. Aplicações podem injetar outro `TokenEstimator` sem alterar o builder.

### Por blocos estruturais

```php
use Omegaalfa\ContextEngine\Ingestion\Chunking\BlockLimitStrategy;

$strategy = new BlockLimitStrategy(limit: 3);
```

Essa estratégia limita quantos nós lógicos podem compor um chunk, independentemente do tamanho textual.

### Fingerprint e versionamento

O fingerprint da estratégia participa da identidade da versão documental. Altere-o sempre que uma mudança puder produzir chunks diferentes:

```text
mesmo documento + mesmo embedding space + fingerprint diferente
                         ↓
                 nova versão de ingestão
```

Isso evita misturar chunks produzidos por algoritmos incompatíveis.

## 🏷️ Metadados dos chunks

Cada chunk herda a metadata do `Document` e recebe contexto estrutural adicional.

| Chave | Significado |
|---|---|
| `block_type` | tipo do bloco que originou o chunk |
| `heading_parent` | heading ou seção mais próxima |
| `hierarchy_path` | caminho legível, como `Manual > API > Erros` |
| `hierarchy_level` | profundidade lógica do chunk |
| `relative_position` | posição do bloco na árvore linearizada |
| `parent_id` | reservado para parent/child retrieval; atualmente `null` |
| `language` | linguagem de código quando conhecida |
| `symbol_type` | classe, interface, trait, enum ou função em código PHP |
| `source` | arquivo ou identificador de origem |
| `version` | versão fornecida pela aplicação |
| `page_start` / `page_end` | intervalo de páginas quando disponível |

Exemplo simplificado:

```php
[
    'source' => '/docs/manual.md',
    'version' => '3',
    'block_type' => 'code',
    'heading_parent' => 'Autenticação',
    'hierarchy_path' => 'Manual da API > Autenticação',
    'hierarchy_level' => 2,
    'relative_position' => 7,
    'language' => 'php',
    'parent_id' => null,
]
```

Tenant, collection, status, documento e posição continuam disponíveis nos campos próprios de `Chunk`; não precisam ser duplicados na metadata.

## 🔌 Criando um novo parser

Suponha um formato `.ini` em que cada seção deve virar um `SectionNode`.

### 1. Implemente o contrato

```php
<?php

declare(strict_types=1);

namespace App\Context\Parser;

use Omegaalfa\ContextEngine\Document\Document;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\DocumentNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\ParagraphNode;
use Omegaalfa\ContextEngine\Ingestion\DocumentModel\SectionNode;
use Omegaalfa\ContextEngine\Ingestion\Parser\DocumentParser;

final readonly class IniParser implements DocumentParser
{
    public function parse(Document $document): DocumentNode
    {
        $data = parse_ini_string($document->content, true, INI_SCANNER_RAW);
        $sections = [];

        foreach ($data ?: [] as $name => $values) {
            $lines = [];

            foreach ((array) $values as $key => $value) {
                $lines[] = new ParagraphNode("{$key}={$value}");
            }

            $sections[] = new SectionNode((string) $name, $lines);
        }

        return new DocumentNode($sections, $document->metadata);
    }
}
```

### 2. Registre o formato

Adicione o formato ao `ParserRegistry` ou forneça um registry especializado à composição da aplicação. O parser deve apenas construir a árvore.

### 3. Teste estes invariantes

- a ordem dos blocos é preservada;
- a hierarquia representa o documento original;
- conteúdo vazio não gera nós inúteis;
- metadata relevante não é perdida;
- entrada inválida produz erro explícito;
- o parser não cria chunks, embeddings ou efeitos externos.

## 🧱 Criando uma nova estratégia

Uma estratégia por bytes, sentenças ou custo de modelo deve implementar três operações:

```php
final readonly class SentenceLimitStrategy implements ChunkingStrategy
{
    public function __construct(private int $limit) {}

    public function fingerprint(): string
    {
        return 'sentences:v1:' . $this->limit;
    }

    public function fits(string $content, int $blockCount): bool
    {
        return preg_match_all('/[.!?]+(?:\s|$)/u', $content) <= $this->limit;
    }

    public function split(string $content): array
    {
        return preg_split('/(?<=[.!?])\s+/u', trim($content)) ?: [];
    }
}
```

Uma estratégia não deve conhecer `Document`, formato, provider ou banco. Ela recebe apenas o conteúdo candidato e a quantidade de blocos.

## 🔄 Compatibilidade com a API existente

O uso público permanece igual:

```php
$report = $engine->ingest($loader);
```

A composição padrão usa `StructuralTextSplitter` internamente. Aplicações que instanciam `RecursiveTextSplitter` diretamente continuam podendo usá-lo; retrieval, embeddings e contratos do vector store não foram alterados.

```text
API pública antiga                    Implementação atual
────────────────────────────────────────────────────────────
ContextEngine::ingest(loader)   ───→  IngestionPipeline
                                      ↓
                                 TextSplitter
                                      ↓
                              parsing + chunking estrutural
```

## 🧪 Cobertura recomendada

Ao evoluir essa área, cubra pelo menos:

- parsing de Markdown, HTML, JSON, XML e PHP;
- ordem e hierarquia dos nós;
- blocos de código, listas, tabelas e citações;
- limites por caracteres, tokens e blocos;
- divisão de um único bloco maior que o limite;
- propagação da metadata original;
- `heading_parent`, `hierarchy_path` e posições;
- estabilidade do fingerprint;
- compatibilidade de `ContextEngine::ingest()`.

Comandos úteis:

```bash
composer test -- tests/Unit/DocumentParsingTest.php
composer analyse
composer style
```

## 🚧 Limites atuais

Esta etapa é propositalmente determinística e não implementa:

- OCR;
- extração ou descrição de imagens;
- interpretação semântica de tabelas;
- resumo automático;
- IA durante o parsing;
- parent/child retrieval;
- descrição automática de figuras.

A presença de `parent_id`, níveis e posições prepara a evolução futura sem antecipar comportamento no retrieval.

## 🔗 Leitura relacionada

- [Exemplos executáveis](../examples/structural-ingestion/README.md) — parsing, chunking, ingestão, busca e comparação de estratégias pela linha de comando.
- [Ingestão](ingestion.md) — batching, embeddings, persistência e falhas parciais.
- [Documentos e splitters](documents-and-splitting.md) — contratos públicos de entrada e divisão.
- [Arquitetura](architecture.md) — fronteiras completas do ContextEngine.
- [Embeddings](embeddings.md) — identidade do espaço vetorial.
- [Extensão](extension-guide.md) — princípios para novos adapters e componentes.
