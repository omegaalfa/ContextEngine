# 📕 Ingestão de PDF

> **PDF é formato de entrada; página é localização física; `Document` é uma janela semântica.**
>
> O ContextEngine extrai páginas ordenadas, agrupa duas ou mais páginas em um `Document` e então entrega o texto ao pipeline normal de chunking, embeddings e persistência.

---

## 🧭 Fluxo completo

```text
arquivo.pdf
    ↓
PopplerPdfTextExtractor
    ↓
ExtractedPdfPage(1), ExtractedPdfPage(2), ...
    ↓
PdfDocumentLoader — janela configurável
    ↓
Document(páginas 1–3), Document(páginas 4–6), ...
    ↓
RecursiveTextSplitter
    ↓
chunks → embeddings → pgvector
```

A extração não conhece embeddings, pgvector ou LLM. A ingestão não conhece Poppler. A separação acontece pelos contratos `PdfTextExtractor` e `DocumentLoader`.

---

## ✨ Componentes incluídos

| Tipo | Responsabilidade |
|---|---|
| `PdfTextExtractor` | Contrato para produzir páginas ordenadas |
| `ExtractedPdfPage` | Número físico, texto e método de extração |
| `PopplerPdfTextExtractor` | Executa `pdftotext` com comando em array, sem shell |
| `PdfDocumentLoader` | Ignora páginas vazias e agrupa páginas em documentos |

OCR, renderização de páginas, detecção de capítulos e remoção automática de cabeçalhos/rodapés não fazem parte desta primeira implementação.

---

## 🧠 Por que não criar sempre um `Document` por página?

Uma página é uma fronteira de impressão, não necessariamente de significado. Uma definição pode começar na página 143 e terminar na 144; pseudocódigo, demonstrações e explicações também atravessam páginas.

```text
Página 143                         Página 144
“O procedimento assume...”  ───→  “...que as subárvores são heaps.”
```

Com `pagesPerDocument: 3`, o splitter enxerga as duas páginas juntas e pode formar chunks que preservam essa continuidade.

A janela final não precisa estar completa. Um PDF com oito páginas e janelas de três produz:

```text
Document 1: páginas 1–3
Document 2: páginas 4–6
Document 3: páginas 7–8  ← janela final preservada
```

> [!NOTE]
> Janelas consecutivas ainda possuem uma fronteira. O desenho atual melhora a continuidade dentro da janela, mas não implementa sobreposição entre janelas de páginas.
> Um parágrafo que atravesse exatamente essa fronteira ainda pode ser separado; não existe união genérica entre janelas.

> [!WARNING]
> Tabelas de PDF são reconhecidas e preservadas como texto por heurísticas. O ContextEngine não reconstrói perfeitamente células, linhas mescladas ou colunas quando o extrator perdeu o layout.

---

## ⚙️ Instalando o Poppler

O PHP não recebe uma dependência Composer adicional. O adapter chama o binário `pdftotext`, que deve ser provisionado pela aplicação ou pela imagem Docker.

Ubuntu, Debian ou WSL:

```bash
sudo apt-get update
sudo apt-get install poppler-utils
pdftotext -v
```

Exemplo de Dockerfile da aplicação:

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*
```

O ContextEngine não instala nem atualiza binários do sistema em runtime.

---

## 🚀 Exemplo completo

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;

$extractor = new PopplerPdfTextExtractor(
    binary: 'pdftotext',
    timeoutSeconds: 60,
    maximumOutputBytes: 50_000_000,
    maximumPages: 1_000,
);

$loader = new PdfDocumentLoader(
    path: '/data/books/algoritmos.pdf',
    tenantId: 'empresa-exemplo',
    extractor: $extractor,
    collection: 'algorithms',
    status: 'active',
    pagesPerDocument: 3,
    metadata: [
        'type' => 'book',
        'title' => 'Algoritmos — Teoria e Prática',
        'edition' => 3,
        'language' => 'pt-BR',
    ],
);

$report = $context->ingestion->ingest($loader);

printf(
    "PDF ingerido: %d chunks persistidos em %d lotes.\n",
    $report->chunksPersisted,
    $report->batchesPersisted,
);
```

`metadata` segue o contrato atual de `Document`: valores escalares ou `null`. Listas como autores devem ser normalizadas pela aplicação para uma string ou campos escalares separados.

---

## 📗 Livro de exemplo da raiz

O repositório possui um exemplo preparado especificamente para `Algoritimos e estrutura de dados em PHP.pdf`:

```bash
php examples/ingest-algorithms-book.php
```

Ele utiliza:

- tenant de `CONTEXT_ENGINE_TENANT_ID`;
- collection `CONTEXT_ENGINE_PDF_COLLECTION`, com fallback `algorithms`;
- `bge-m3` com 1.024 dimensões;
- três páginas por `Document`;
- até 500 páginas e 100 MB de texto extraído;
- timeout de extração configurável;
- Gemini somente como LLM do contexto, sem chamada HTTP durante a ingestão.

Para pesquisar o conteúdo depois, configure o retrieval com a mesma collection `algorithms`.
## 🏷️ Metadata gerada automaticamente

Para uma janela com páginas 143, 144 e 145:

```php
[
    'source' => '/caminho/canonico/algoritmos.pdf',
    'format' => 'pdf',
    'page_start' => 143,
    'page_end' => 145,
    'page_numbers' => '143,144,145',
    'extraction_method' => 'text',
]
```

Metadata fornecida pelo usuário é preservada, mas os campos técnicos acima são controlados pelo loader para evitar informações contraditórias.

O ID de cada `Document` é determinístico e considera:

```text
tenant + caminho canônico + primeira página + última página
```

Assim, repetir a ingestão da mesma janela atualiza a mesma identidade lógica; outro tenant ou outro intervalo produz identidade independente.

---

## 🔖 Markers internos de página

O conteúdo combinado mantém a origem física:

```text
[[CONTEXT_ENGINE_PAGE:143]]

texto da página 143...

[[CONTEXT_ENGINE_PAGE:144]]

texto da página 144...
```

Se o texto original já contiver o prefixo reservado, o loader o normaliza para `[[SOURCE_PAGE:`. Isso evita que conteúdo do PDF imite um marker criado pela biblioteca.

O `RecursiveTextSplitter` atual não interpreta esses markers. Os chunks herdam `page_start` e `page_end` da janela completa. Portanto:

- a janela continua citável;
- o texto preserva markers suficientes para inspeção;
- página exata por chunk ainda não é garantida;
- um splitter futuro consciente de markers poderá calcular intervalos precisos por chunk.

> [!IMPORTANT]
> Não apresente `page_start/page_end` do chunk como precisão de linha ou página exata. Hoje esses campos representam o intervalo do `Document` que originou o chunk.

---

## 🛡️ Segurança operacional

`PopplerPdfTextExtractor` aplica:

- caminho canonicalizado por `realpath()`;
- arquivo regular e legível;
- validação da assinatura `%PDF-`;
- comando entregue a `proc_open()` como array;
- `bypass_shell` habilitado;
- temporário exclusivo;
- remoção do temporário em `finally`;
- timeout configurável;
- limite do arquivo de texto produzido;
- limite opcional de páginas;
- stderr truncado na exceção pública.

A aplicação ainda deve executar arquivos não confiáveis em ambiente isolado:

- usuário sem privilégios;
- filesystem e diretório temporário restritos;
- container sem acesso à rede;
- limites de CPU, memória e tamanho de upload;
- Poppler atualizado;
- fila de trabalho separada da requisição web.

O limite de saída é conferido antes de o PHP materializar o texto, mas o Poppler ainda pode escrever até terminar. A quota de disco do container continua sendo uma defesa necessária.

---

## 📷 PDFs escaneados

Esta implementação cobre PDFs com camada textual. Um PDF escaneado pode produzir páginas vazias; o loader as ignora.

A evolução recomendada é um decorator:

```text
OcrFallbackPdfTextExtractor
    ├── primary: PopplerPdfTextExtractor
    ├── renderer: PdfPageRenderer
    └── ocr: OcrEngine
```

A decisão de OCR deve avaliar mais que quantidade de caracteres: proporção imprimível, palavras reconhecíveis, caracteres de substituição e repetição anormal também importam. Tesseract, Imagick e Ghostscript devem permanecer opcionais.

---

## ✅ Garantias e limitações

| Comportamento | Estado |
|---|---:|
| Extração textual com Poppler | ✅ |
| Ordem e número físico das páginas | ✅ |
| Janela configurável | ✅ |
| Janela final incompleta | ✅ |
| Páginas vazias ignoradas | ✅ |
| IDs determinísticos por tenant/janela | ✅ |
| Comando sem shell | ✅ |
| OCR | ❌ futuro |
| Capítulos e seções automáticos | ❌ futuro |
| Página exata calculada por chunk | ❌ futuro |
| Sobreposição entre janelas de páginas | ❌ futuro |
