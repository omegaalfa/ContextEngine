# Documentos, loaders e splitters

## Document

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Document\Document;

$document = new Document(
    id: 'manual-2026',
    tenantId: 'tenant-42',
    content: "Primeiro parágrafo.\n\nSegundo parágrafo.",
    metadata: ['source' => 'manual.txt'],
    collection: 'manuals',
    status: 'active',
);
```

Assinatura: `__construct(string $id, string $tenantId, string $content, array $metadata = [], string $collection = 'default', string $status = 'active')`. ID, tenant, conteúdo, collection e status não podem ser vazios. Metadata aceita somente valores escalares ou `null` conforme PHPDoc.

## Loader

`DocumentLoader::load(): iterable<Document>` permite leitura incremental. `TextFileLoader(string $path, string $tenantId)` abre o arquivo em modo binário, separa documentos por linhas vazias e fecha o handle mesmo em falha. Ele gera IDs por hash de caminho e índice, preserva o caminho em `source` e usa collection/status padrões.

### Loader customizado

```php
<?php

declare(strict_types=1);

use Omegaalfa\ContextEngine\Contract\DocumentLoader;
use Omegaalfa\ContextEngine\Document\Document;

final readonly class ArticleLoader implements DocumentLoader
{
    /** @param iterable<array{id:string, body:string}> $rows */
    public function __construct(
        private iterable $rows,
        private string $tenantId,
    ) {}

    public function load(): iterable
    {
        foreach ($this->rows as $row) {
            yield new Document(
                id: $row['id'],
                tenantId: $this->tenantId,
                content: $row['body'],
                collection: 'articles',
            );
        }
    }
}
```

## RecursiveTextSplitter

Assinatura: `__construct(int $chunkSize = 1000, int $overlap = 150, TextNormalizer $normalizer = new TextNormalizer())`, `fingerprint(): string` e `split(Document $document): iterable<Chunk>`.

Ele normaliza CRLF, tabs e espaços e percorre o texto por offsets. Dentro de cada janela procura, nessa ordem, limites de parágrafo, linha, sentença e espaço; quando nenhum corte seguro existe, usa o limite de caracteres. Os separadores apenas escolhem onde terminar a janela: nunca fazem o cursor saltar texto ainda não coberto.

`overlap` deve ser `>= 0` e menor que `chunkSize`. O chunk seguinte começa exatamente `overlap` caracteres antes do fim do anterior. Isso permite reconstruir o texto normalizado removendo o prefixo repetido de cada chunk subsequente, sem lacunas ou reordenação. Tenant, collection, status e metadata são propagados.

O ID é SHA-256 de tenant, documento, posição e conteúdo final. Ele é determinístico para este splitter, mas a API permite splitters externos; o banco não pressupõe ID global.

O fingerprint do splitter inclui a versão do algoritmo, `chunkSize` e `overlap`. Ele participa da identidade de `DocumentVersion`: mudar a estratégia cria outra versão completa, impedindo que chunks produzidos por configurações incompatíveis sejam misturados. Splitters próprios devem aplicar a mesma regra a toda opção que altere sua saída.

Documento/conteúdo vazio é rejeitado antes do splitter. Para textos grandes, `split()` produz chunks como generator, embora a partição de cada fragmento use strings em memória.
