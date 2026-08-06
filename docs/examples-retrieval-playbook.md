# 🧪 Playbook de exemplos de retrieval

> Uma trilha prática para entender busca vetorial, busca lexical, fusão de rankings, expansão de contexto, diagnósticos e respostas RAG.

Os exemplos desta página são independentes e podem ser executados diretamente com PHP. Eles usam componentes locais de demonstração, portanto são ideais para estudar o pipeline sem configurar banco, credenciais ou serviços externos.

## 🗺️ Trilha recomendada

```text
01 Busca vetorial
        ↓
02 Busca lexical
        ↓
03 Planejamento de consultas
        ↓
04 Fusão RRF
        ↓
05 Expansão por vizinhos
        ↓
06 Busca híbrida
        ↓
07 Diagnósticos completos
        ↓
08 Pipeline RAG completo
        ↓
09 Resposta buffered  ───  10 Streaming
```

Se você está começando, execute os arquivos nessa ordem. Cada etapa adiciona apenas um conceito novo ao fluxo anterior.

## ▶️ Executando todos os exemplos

A partir da raiz do projeto:

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

Cada script apresenta:

- etapas executadas pelo pipeline;
- chunks encontrados e sua ordenação;
- tempo de execução;
- quantidade de documentos encontrados;
- quantidade de chunks selecionados.

As perguntas cobrem buscas conceituais, geração de código e identificadores exatos como `ERR_PAYMENT_1047`, SKUs, classes e funções.

## 📋 Visão rápida

| Exemplo | Conceito principal | Use para entender |
|---|---|---|
| `01-vector-search.php` | similaridade vetorial | busca semântica |
| `02-lexical-search.php` | correspondência textual | códigos, nomes e identificadores |
| `03-multi-query.php` | reescrita heurística | perguntas decompostas em subconsultas |
| `04-rrf.php` | Reciprocal Rank Fusion | combinação de rankings diferentes |
| `05-context-expansion.php` | chunks vizinhos | recuperação do contexto ao redor |
| `06-hybrid-search.php` | vetorial + lexical | equilíbrio entre significado e termos exatos |
| `07-diagnostics.php` | observabilidade | decisões e tempos internos |
| `08-end-to-end-rag.php` | pipeline completo | retrieval, prompt e modelo |
| `09-ask.php` | resposta buffered | resultado completo em uma chamada |
| `10-stream.php` | resposta incremental | consumo de deltas em tempo real |

## 1️⃣ Busca vetorial

```bash
php examples/01-vector-search.php
```

### Fluxo

```text
Pergunta → EmbeddingProvider → vetor da pergunta
                                  ↓
                             VectorStore
                                  ↓
                     chunks por similaridade
```

O exemplo usa apenas recuperação vetorial. A busca lexical fica desativada para deixar claro como a distância semântica influencia o ranking.

**Observe:**

- distância de cada resultado;
- ordem dos chunks;
- resultados semanticamente próximos mesmo sem repetir todas as palavras da pergunta.

## 2️⃣ Busca lexical

```bash
php examples/02-lexical-search.php
```

A busca lexical compara termos e identificadores presentes na pergunta com o conteúdo armazenado.

Ela é especialmente útil para:

- códigos de erro, como `ERR_PAYMENT_1047`;
- SKUs, como `AX9-RED`;
- nomes de classes e métodos;
- termos técnicos que não devem ser aproximados semanticamente.

```text
"ERR_PAYMENT_1047"
          ↓ correspondência exata
chunk que contém ERR_PAYMENT_1047
```

## 3️⃣ Planejamento com múltiplas consultas

```bash
php examples/03-multi-query.php
```

O `HeuristicQueryRewriter` transforma uma pergunta ampla em consultas menores. Cada consulta é executada separadamente antes da fusão dos resultados.

```text
Pergunta original
├── consulta semântica principal
├── identificadores detectados
├── nomes de classes ou funções
└── termos técnicos relevantes
```

O script imprime as consultas geradas e os resultados individuais de cada uma.

## 4️⃣ Fusão de rankings com RRF

```bash
php examples/04-rrf.php
```

O Reciprocal Rank Fusion combina rankings sem exigir que todos usem a mesma escala de pontuação.

```text
Ranking A       Ranking B
1. chunk-x      1. chunk-y
2. chunk-y      2. chunk-z
3. chunk-z      3. chunk-x
       \          /
        \        /
          RRF
           ↓
   ranking consolidado
```

O exemplo mostra rankings individuais e o ranking final após a fusão.

## 5️⃣ Expansão de contexto

```bash
php examples/05-context-expansion.php
```

Um chunk relevante pode depender do parágrafo anterior ou da continuação seguinte. A expansão recupera vizinhos pela posição documental.

```text
chunk anterior ← chunk encontrado → chunk seguinte
```

O script identifica quais chunks vieram do ranking original e quais foram adicionados como vizinhos.

## 6️⃣ Busca híbrida

```bash
php examples/06-hybrid-search.php
```

Executa a mesma pergunta em três modos:

| Modo | Ponto forte | Limitação isolada |
|---|---|---|
| Vetorial | significado e paráfrases | pode diluir identificadores exatos |
| Lexical | termos literais | não compreende bem paráfrases |
| Híbrido | combina os dois sinais | exige fusão e diagnóstico adequados |

Compare os primeiros chunks de cada estratégia e observe como a seleção final muda.

## 7️⃣ Diagnósticos completos

```bash
php examples/07-diagnostics.php
```

Este é o exemplo indicado para investigar por que determinado resultado foi selecionado.

Ele apresenta:

- consultas planejadas;
- hits de cada consulta;
- IDs após RRF;
- vizinhos adicionados;
- decisões da seleção adaptativa;
- tempo gasto em cada etapa.

```text
planejamento → busca → fusão → expansão → seleção
    ms           ms      ms        ms          ms
```

## 8️⃣ Pipeline RAG completo

```bash
php examples/08-end-to-end-rag.php
```

### Fluxo completo

```text
Question
   ↓
planejamento de consultas
   ↓
retrieval vetorial + lexical
   ↓
RRF + expansão + seleção
   ↓
ContextPromptBuilder
   ↓
LanguageModel
   ↓
Answer + fontes + diagnósticos
```

O exemplo executa resposta completa e streaming sobre o mesmo contexto, permitindo comparar os dois modos.

## 💬 `ask()` ou `stream()`?

### Resposta completa com `ask()`

```bash
php examples/09-ask.php
```

```php
$answer = $engine->ask($question);
echo $answer->content;
```

Use quando a aplicação precisa receber a resposta final de uma vez. Esse é o comportamento padrão e mais simples.

### Resposta incremental com `stream()`

```bash
php examples/10-stream.php
```

```php
foreach ($engine->stream($question) as $delta) {
    if ($delta->final) {
        break;
    }

    echo $delta->content;
}
```

Use em interfaces conversacionais ou terminais que devem apresentar conteúdo à medida que chega.

> Streaming incremental depende do provider implementar `StreamingLanguageModel`. OpenAI possui suporte incremental real; outros providers podem operar apenas no modo buffered.

## 🧭 Qual exemplo devo executar?

| Quero entender... | Execute |
|---|---|
| similaridade semântica | `01-vector-search.php` |
| códigos e identificadores exatos | `02-lexical-search.php` |
| decomposição de perguntas | `03-multi-query.php` |
| combinação de rankings | `04-rrf.php` |
| contexto antes e depois do resultado | `05-context-expansion.php` |
| busca vetorial e textual combinadas | `06-hybrid-search.php` |
| decisões internas do retrieval | `07-diagnostics.php` |
| fluxo RAG completo | `08-end-to-end-rag.php` |
| resposta final simples | `09-ask.php` |
| saída incremental | `10-stream.php` |

## 🔗 Próximos passos

- [Retrieval para iniciantes](retrieval-for-beginners.md)
- [Pipeline de retrieval](retrieval-pipeline.md)
- [Busca híbrida](hybrid-search.md)
- [Seleção adaptativa de contexto](adaptive-context-selection.md)
- [Pipeline RAG](rag-pipeline.md)
- [Exemplos de ingestão estrutural](../examples/structural-ingestion/README.md)
