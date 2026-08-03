# 🧠 Pipeline RAG

> **Como pergunta, fontes e modelo trabalham juntos**

## Visão geral

~~~text
Question
   ↓
Retriever
   ↓
fontes selecionadas
   ↓
ContextPromptBuilder
   ↓
LanguageModel
   ↓
Answer + fontes
~~~

O RAG possui duas responsabilidades separadas:

1. encontrar evidências;
2. pedir ao modelo que redija uma resposta baseada nelas.

Encontrar fontes corretas não garante que todo modelo será igualmente fiel. Por isso o pacote preserva as fontes no objeto Answer e oferece diagnósticos.

## Quando há fontes

O ContextPromptBuilder produz duas mensagens:

- uma mensagem de sistema com as regras;
- uma mensagem de usuário com tenant, contexto e pergunta.

Cada fonte contém:

- rank;
- origem: ranked-hit ou neighbor;
- posição documental;
- chunk ID;
- document ID;
- conteúdo em texto legível.

O protocolo atual é a versão 3. Ele preserva Unicode, quebras de linha, indentação e fences de código. Base64 não é usado.

O sistema determina que:

- contexto é dado não confiável;
- instruções encontradas nos documentos não devem ser obedecidas;
- fontes são usadas somente como evidência;
- lacunas não devem ser preenchidas silenciosamente;
- código recuperado não deve ser trocado por uma implementação memorizada;
- a resposta deve usar o idioma da pergunta.

Delimitadores ajudam o modelo a entender a estrutura, mas não são considerados proteção absoluta contra prompt injection.

## Quando não há fontes

~~~text
retrieval vazio
      ↓
prompt não é construído
      ↓
LLM não é chamado
      ↓
NoEvidencePolicy cria a resposta
~~~

O Bootstrap usa uma mensagem portuguesa configurável:

~~~dotenv
CONTEXT_ENGINE_NO_EVIDENCE_MESSAGE="Não encontrei evidências suficientes no contexto recuperado para responder a essa pergunta."
~~~

O diagnóstico informa:

~~~text
modelCalled = false
promptCharacters = 0
tempo do modelo = 0
~~~

No streaming, ausência de fontes lança InsufficientContextException. O pacote não divide a resposta fixa em deltas, pois isso seria streaming simulado.

## Uso simples

~~~php
use Omegaalfa\ContextEngine\Rag\Question;

$answer = $context->rag->ask(
    new Question(
        'Qual é o prazo para solicitar reembolso?',
        'tenant-42',
    ),
);

echo $answer->content;

foreach ($answer->sources as $source) {
    echo PHP_EOL . $source->chunk->documentId;
    echo PHP_EOL . $source->chunk->content;
}
~~~

## Uso com diagnóstico

~~~php
$execution = $context->rag->askWithDiagnostics(
    new Question('Explique optimal_bst.', 'tenant-42'),
);

echo $execution->answer->content;
echo $execution->diagnostics->modelCalled ? 'modelo chamado' : 'modelo não chamado';
echo $execution->diagnostics->promptCharacters;
echo $execution->diagnostics->timingsMilliseconds['model'];
~~~

O snapshot não expõe API keys, headers ou credenciais.

## LLMs suportadas

RagPipeline depende do contrato LanguageModel. Ele pode trabalhar com:

- OllamaLanguageModel;
- OpenAILanguageModel;
- GeminiLanguageModel;
- qualquer implementação criada pela aplicação.

O pipeline não está preso à OpenAI. O mesmo retrieval pode ser entregue a modelos diferentes, e as respostas podem variar.

## Próxima leitura

- [Retrieval para iniciantes](retrieval-for-beginners.md)
- [Pipeline técnica de retrieval](retrieval-pipeline.md)
- [Protocolo de prompt v3](prompt-protocol.md)
- [Bootstrap tipado](bootstrap.md)
