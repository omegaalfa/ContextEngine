# 🔎 Retrieval para quem está começando

> **Como o ContextEngine escolhe as fontes que serão entregues à inteligência artificial**

Este guia não exige conhecimento prévio de RAG, embeddings ou busca vetorial.

---

## 🌟 A ideia mais importante

O ContextEngine não envia todos os documentos para a IA.

Ele primeiro procura trechos relacionados, organiza os melhores e entrega somente as fontes permitidas pela configuração.

Imagine uma biblioteca:

| ContextEngine | Analogia simples |
|---|---|
| Pergunta | O pedido feito ao bibliotecário |
| Consulta | Uma forma de pesquisar no catálogo |
| Chunk | Uma página ou trecho de livro |
| Retriever | O bibliotecário |
| RRF | Um torneio entre listas de resultados |
| Contexto final | As páginas colocadas sobre a mesa |
| LLM | A pessoa que lê as páginas e escreve a resposta |

> [!IMPORTANT]
> Encontrar cinco candidatos não significa entregar cinco fontes ao modelo. Busca, ranking e seleção final são etapas diferentes.

---

## 🗺️ O caminho completo

~~~text
Pergunta original
      │
      ▼
🧭 Planejamento de consultas
   cria uma ou mais formas de procurar
      │
      ▼
🔎 Busca vetorial
   encontra candidatos para cada consulta
      │
      ▼
🏆 RRF + deduplicação
   combina rankings e remove repetições
      │
      ▼
📎 Vizinhos opcionais
   acrescenta trechos anteriores ou posteriores
      │
      ▼
🎒 Orçamento final
   limita fontes e tamanho total
      │
      ▼
🧾 Prompt
   recebe apenas as fontes escolhidas
      │
      ▼
🤖 LLM
   redige a resposta
~~~

---

## 🧭 1. Por que criar várias consultas?

Uma pergunta humana contém palavras que explicam a intenção, mas um identificador exato pode ser melhor para localizar a fonte.

Pergunta:

~~~text
Converta para PHP 8.4 a função Python optimal_bst presente no contexto.
~~~

Com o planejamento heurístico habilitado, a busca pode usar:

~~~text
1. Pergunta original completa
2. PHP
3. optimal_bst
4. Python
~~~

A pergunta original nunca é substituída. As variantes servem apenas para procurar evidências.

Ativação:

~~~dotenv
CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING=1
~~~

Sem essa configuração, o ContextEngine usa somente a pergunta original.

---

## 🔎 2. O primeiro limite: candidatos por consulta

Esta configuração:

~~~dotenv
CONTEXT_ENGINE_RETRIEVAL_LIMIT=3
~~~

significa:

> Cada consulta pode trazer até três candidatos.

Com quatro consultas:

~~~text
pergunta original → até 3
PHP               → até 3
optimal_bst       → até 3
Python            → até 3
~~~

Podem surgir até doze ocorrências, mas vários resultados podem apontar para o mesmo chunk.

Essas ocorrências ainda não são as fontes finais.

---

## 📏 3. Distância máxima

A busca vetorial devolve uma distância. Para a métrica cosseno usada no exemplo:

~~~text
distância menor = conteúdo semanticamente mais próximo
~~~

Com:

~~~dotenv
CONTEXT_ENGINE_MAXIMUM_DISTANCE=0.60
~~~

resultados acima de 0.60 são rejeitados.

Durante diagnóstico, o corte pode ser desabilitado:

~~~dotenv
CONTEXT_ENGINE_MAXIMUM_DISTANCE=off
~~~

> [!WARNING]
> Distância não é porcentagem de confiança. O valor adequado depende do modelo de embedding e dos seus documentos.

---

## 🏆 4. O que o RRF faz?

Cada consulta produz seu próprio ranking. O mesmo chunk pode aparecer em várias listas.

O Reciprocal Rank Fusion, ou RRF, funciona como um campeonato:

- primeiro lugar recebe mais pontos;
- segundo lugar recebe um pouco menos;
- aparecer bem em várias consultas acumula pontos;
- chunks repetidos viram um único resultado final.

~~~text
consulta A: chunk azul em 1º
consulta B: chunk azul em 2º
consulta C: chunk azul em 1º
                     │
                     ▼
       chunk azul ganha força no RRF
~~~

O ContextEngine usa:

~~~text
score += 1 / (60 + posição)
~~~

Você não precisa calcular esse valor manualmente.

---

## 🥇 5. O segundo limite: vencedores do RRF

Esta configuração:

~~~dotenv
CONTEXT_ENGINE_FUSED_LIMIT=1
~~~

significa:

> Depois de combinar e deduplicar todos os rankings, mantenha somente o melhor chunk.

Exemplo:

~~~text
12 ocorrências
      ↓ deduplicação
7 chunks únicos
      ↓ RRF
ranking final
      ↓ fusedLimit=1
1 vencedor
~~~

---

## 📎 6. Para que servem os vizinhos?

Às vezes um chunk termina assim:

~~~text
O pseudocódigo aparece a seguir:
~~~

e o algoritmo está no chunk seguinte.

Nesse caso, é possível pedir trechos próximos:

~~~dotenv
CONTEXT_ENGINE_NEIGHBOR_BEFORE=1
CONTEXT_ENGINE_NEIGHBOR_AFTER=1
~~~

O ContextEngine só aceita vizinhos do mesmo:

- tenant;
- collection;
- status;
- documento;
- versão ativa;
- espaço vetorial.

Ele usa a posição real do chunk no documento, nunca distância ou hash.

---

## 🎒 7. O terceiro limite: fontes finais

Esta configuração:

~~~dotenv
CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT=1
~~~

significa:

> Depois do RRF e dos vizinhos, no máximo um chunk poderá chegar ao prompt.

É uma proteção final. Mesmo que etapas anteriores tenham produzido muitos candidatos, somente a quantidade permitida chega ao LLM.

Também existe um orçamento por caracteres:

~~~dotenv
CONTEXT_ENGINE_MAXIMUM_CONTEXT_CHARACTERS=18000
~~~

O ContextEngine não corta um chunk silenciosamente no meio. Se ele não couber, é descartado inteiro e registrado no diagnóstico.

---

## 🎛️ Os quatro limites em português claro

| Variável | Significado |
|---|---|
| **RETRIEVAL_LIMIT** | candidatos que cada consulta pode buscar |
| **FUSED_LIMIT** | vencedores que continuam depois do RRF |
| **CONTEXT_CHUNK_LIMIT** | fontes que podem chegar ao prompt |
| **MAXIMUM_CONTEXT_CHARACTERS** | tamanho total máximo do contexto |

Exemplo completo:

~~~text
4 consultas × 3 candidatos = até 12 ocorrências
                  │
                  ▼
deduplicação + RRF = 7 chunks únicos
                  │
                  ▼
fusedLimit = 2    = 2 vencedores
                  │
                  ▼
vizinhos           = 4 candidatos contextuais
                  │
                  ▼
contextChunkLimit=2 = 2 fontes entregues ao LLM
~~~

---

## 🎯 Por que uma única fonte resolveu optimal_bst?

O melhor chunk já continha:

- a explicação do algoritmo;
- a função Python optimal_bst;
- os parâmetros;
- as tabelas e, w e root;
- o fluxo de controle;
- o retorno;
- um exemplo de uso.

As demais fontes falavam sobre heap, subarranjo máximo e quadro de Young.

Antes:

~~~text
fonte 1 → optimal_bst ✅
fonte 2 → heap ❌
fonte 3 → subarranjo máximo ❌
fonte 4 → heap ❌
fonte 5 → quadro de Young ❌
~~~

Um modelo local menor se distraiu com os trechos irrelevantes e respondeu sobre heap.

Depois:

~~~text
consultas encontram optimal_bst
            ↓
RRF escolhe o chunk correto
            ↓
fusedLimit = 1
            ↓
contextChunkLimit = 1
            ↓
LLM recebe somente a evidência correta
~~~

Configuração usada:

~~~dotenv
CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING=1
CONTEXT_ENGINE_RETRIEVAL_LIMIT=3
CONTEXT_ENGINE_FUSED_LIMIT=1
CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT=1
CONTEXT_ENGINE_MAXIMUM_DISTANCE=0.60
~~~

---

## ❓ Uma fonte é sempre melhor?

**Não.**

Uma fonte funcionou porque a evidência completa estava em um único chunk.

Use mais fontes quando:

- o código continua no próximo trecho;
- o título está no trecho anterior;
- fórmula e explicação estão separadas;
- a pergunta compara dois conceitos;
- documentos diferentes fornecem partes da resposta.

Comparar Bellman-Ford e Dijkstra, por exemplo, provavelmente exige uma fonte de cada algoritmo.

> [!TIP]
> O objetivo não é usar o menor número possível. É entregar evidência suficiente com o mínimo de ruído.

---

## 🧪 Configurações iniciais

São pontos de partida, não regras universais.

### Função ou identificador exato

~~~dotenv
CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING=1
CONTEXT_ENGINE_RETRIEVAL_LIMIT=3
CONTEXT_ENGINE_FUSED_LIMIT=1
CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT=1
CONTEXT_ENGINE_MAXIMUM_CONTEXT_CHARACTERS=12000
~~~

### Pergunta conceitual

~~~dotenv
CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING=1
CONTEXT_ENGINE_RETRIEVAL_LIMIT=5
CONTEXT_ENGINE_FUSED_LIMIT=3
CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT=3
CONTEXT_ENGINE_MAXIMUM_CONTEXT_CHARACTERS=18000
~~~

### Comparação entre dois assuntos

~~~dotenv
CONTEXT_ENGINE_HEURISTIC_QUERY_PLANNING=1
CONTEXT_ENGINE_RETRIEVAL_LIMIT=5
CONTEXT_ENGINE_FUSED_LIMIT=4
CONTEXT_ENGINE_CONTEXT_CHUNK_LIMIT=4
CONTEXT_ENGINE_MAXIMUM_CONTEXT_CHARACTERS=24000
~~~

---

## 🛡️ O que acontece sem fontes?

O ContextEngine não chama o modelo:

~~~text
zero fontes
    ↓
prompt não é construído
    ↓
LanguageModel não é chamado
    ↓
resposta determinística
~~~

Mensagem padrão do Bootstrap:

~~~text
Não encontrei evidências suficientes no contexto recuperado para responder a essa pergunta.
~~~

Personalização:

~~~dotenv
CONTEXT_ENGINE_NO_EVIDENCE_MESSAGE="Não encontrei evidências suficientes para responder."
~~~

Essa proteção impede alucinação com contexto vazio. Quando há fontes, ainda é necessário controlar relevância e ruído.

---

## 🔬 Como enxergar cada etapa

Execute sem chamar o LLM:

~~~bash
php examples/retrieval-diagnostics.php \
  "Converta para PHP 8.4 a função Python optimal_bst presente no contexto."
~~~

O relatório mostra:

- consultas executadas;
- hits de cada consulta;
- rank e distância;
- ranking fundido;
- vizinhos;
- fontes finais;
- descartes;
- caracteres;
- tempos.

### Checklist de diagnóstico

~~~text
1. O arquivo original contém a informação?
2. O chunk salvo contém o trecho?
3. Tenant, collection, status e espaço coincidem?
4. A busca encontrou o chunk?
5. maximumDistance descartou o resultado?
6. O RRF manteve o chunk?
7. O orçamento enviou o chunk ao prompt?
8. A LLM respeitou a evidência?
~~~

---

## ✅ Resumo para guardar

~~~text
retrievalLimit    = candidatos por busca
fusedLimit        = vencedores depois do RRF
contextChunkLimit = fontes finais
maximumCharacters = tamanho total
~~~

> **Mais fontes não significa automaticamente uma resposta melhor.**
>
> Procure evidência suficiente, relevante e sem ruído desnecessário.
