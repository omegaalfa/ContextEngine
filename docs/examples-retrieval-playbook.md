# Retrieval Examples Playbook

This guide maps practical examples you can run directly to understand each retrieval stage and the difference between buffered and streaming answers.

All scripts are standalone and executable with plain PHP:

```bash
php examples/01-vector-search.php
php examples/02-lexical-search.php
php examples/03-multi-query.php
php examples/04-rrf.php
php examples/05-context-expansion.php
php examples/06-hybrid-search.php
php examples/07-diagnostics.php
php examples/08-end-to-end-rag.php
php examples/09-ask.php
php examples/10-stream.php
```

Each script prints:

- stage-by-stage pipeline output;
- execution time;
- number of documents found;
- number of selected chunks.

The examples use different question styles: conceptual queries, code-generation style prompts, identifiers such as `ERR_PAYMENT_1047`, SKUs, classes, and functions.

## 01-vector-search.php

Focus: vector search only.

- Uses vector retrieval with lexical disabled.
- Good for understanding semantic similarity ranking.

## 02-lexical-search.php

Focus: lexical search only.

- Uses keyword and identifier overlap.
- Useful for exact terms such as error codes and SKUs.

## 03-multi-query.php

Focus: heuristic query planning.

- Shows generated sub-queries.
- Prints results for each query independently.

## 04-rrf.php

Focus: Reciprocal Rank Fusion.

- Prints individual rankings.
- Prints the fused ranking after RRF.

## 05-context-expansion.php

Focus: neighbor chunk expansion.

- Retrieves adjacent chunks around ranked evidence.
- Shows which chunks were brought as neighbors.

## 06-hybrid-search.php

Focus: vector vs lexical vs hybrid comparison.

- Runs three strategies with the same question.
- Compares top chunks and final selection.

## 07-diagnostics.php

Focus: complete retrieval diagnostics.

- Prints query planning, hits per query, fused ids, neighbor ids, context-selection decisions, and timings.

## 08-end-to-end-rag.php

Focus: full pipeline and answer modes.

- Runs planning -> retrieval -> RRF -> expansion -> selection -> prompt -> model.
- Demonstrates both answer APIs:
  - `ask(...)` returns one complete buffered answer.
  - `stream(...)` returns incremental deltas.

## ask() vs stream()

Default behavior remains buffered:

- `ask(...)` is the default path for full answer output.
- `stream(...)` must be called explicitly for incremental output.

Dedicated runnable scripts are available:

- `examples/09-ask.php` for complete final answer mode.
- `examples/10-stream.php` for incremental delta mode.

In this repository:

- OpenAI language model supports real incremental streaming.
- Other models may remain buffered depending on provider implementation.
