# Document parsing and structural chunking

## Architecture

```text
DocumentLoader -> Document -> DocumentParser -> DocumentNode tree
    -> ChunkBuilder -> Chunk -> EmbeddingProvider -> VectorStore
```

Reading, parsing, chunking, embedding, and persistence are independent. Existing callers keep using `ContextEngine::ingest(DocumentLoader $loader)`.

## Document model

Every parser returns an immutable `DocumentNode` containing ordered nodes. Supported node types are `SectionNode`, `HeadingNode`, `ParagraphNode`, `CodeBlockNode`, `TableNode`, `ListNode`, and `QuoteNode`. Nodes expose content, children, type, and scalar metadata.

Chunk metadata includes `block_type`, `heading_parent`, `hierarchy_path`, `hierarchy_level`, `relative_position`, and the reserved `parent_id`. Original document metadata remains available.

## Parsers

`ParserRegistry` checks `metadata.format`, then `metadata.type`, then the extension in `metadata.source`. It supports plain text, Markdown, HTML, JSON, XML, and PHP. PDF text produced by the existing loader uses the plain-text parser while retaining page metadata.

To add a parser, implement `DocumentParser`, build only logical nodes, register the format in `ParserRegistry`, and keep parsing deterministic without network or AI calls.

## Chunking

`ChunkBuilder` walks the tree in source order, tracks headings and sections, and combines complete blocks while the strategy accepts the candidate. Oversized blocks are split only as a fallback.

- `CharacterLimitStrategy`: maximum UTF-8 character count.
- `TokenLimitStrategy`: maximum estimated tokens with a replaceable `TokenEstimator`.
- `BlockLimitStrategy`: maximum logical block count.

New strategies implement `ChunkingStrategy`. Their fingerprint must change whenever output-affecting behavior changes because document version identity uses it.

```php
use Omegaalfa\ContextEngine\Ingestion\Chunking\TokenLimitStrategy;
use Omegaalfa\ContextEngine\Splitter\StructuralTextSplitter;

$splitter = new StructuralTextSplitter(new TokenLimitStrategy(256));
$chunks = $splitter->split($document);
```

The old `RecursiveTextSplitter` remains public for applications that instantiate it directly. Retrieval and storage contracts are unchanged.
