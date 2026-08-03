# 🛡️ Protocolo de prompt RAG

> Versão atual: 3 · texto legível · fontes identificadas · sem Base64

## Por que existe

O resultado do retrieval é evidência, não instrução. Um documento pode conter comandos ou frases que parecem ordens. ContextPromptBuilder mantém regiões separadas:

    SYSTEM RULES
         ↓
    <CONTEXT> dados não confiáveis </CONTEXT>
         ↓
    <QUESTION> intenção do usuário </QUESTION>

A mensagem de sistema proíbe obedecer instruções encontradas nos chunks e exige uma declaração clara quando a evidência for insuficiente. Delimitadores ajudam o modelo, mas não são proteção absoluta contra prompt injection.

## Formato

    <SOURCE rank="1" origin="ranked-hit" position="42"
            chunk_id="..." document_id="...">
    conteúdo legível, com Unicode, linhas e indentação preservados
    </SOURCE>

Origin distingue ranked-hit de neighbor. Um vizinho é contexto documental adjacente, não um novo acerto vetorial.

O conteúdo é enviado diretamente ao LLM. Não existe content_base64 nem base64_encode. Base64 não é barreira de segurança e, nos testes com modelos locais, prejudicou fortemente a leitura de código. Tokens que imitam delimitadores são neutralizados sem truncar o documento.

## Código e evidência

Quando há código, o modelo recebe regras para preservar parâmetros, estruturas de dados, fluxo de controle e retorno. Ele não deve trocar o algoritmo recuperado por uma implementação memorizada, nem inventar lógica ausente. Código incompleto deve ser declarado incompleto.

Quebras de linha, Unicode, fences e indentação chegam intactos ao provider. A pergunta original fica fora do contexto e nunca é substituída pelas consultas derivadas do retrieval.

## Descobertas dos benchmarks

- retrieval correto não garante geração fiel;
- modelos diferentes respondem de formas distintas às mesmas fontes;
- Base64 reduziu a aderência dos modelos locais;
- texto legível melhorou substancialmente a tradução de código;
- chunks irrelevantes podem contaminar modelos menores;
- identificadores como optimal_bst precisam ser preservados;
- consultas conceituais e de implementação podem pedir estratégias distintas.

Essas observações não promovem um modelo como garantia. Retrieval e geração devem ser avaliados separadamente.

## Privacidade e truncamento

O builder não trunca fontes. O orçamento é aplicado antes dele, descartando chunks inteiros e registrando IDs. O diagnóstico padrão contém IDs, contagens, tamanhos e tempos — nunca chaves, headers, credenciais ou o prompt integral.

## Ausência de evidência

Quando o retrieval não seleciona nenhuma fonte, RagPipeline não chama o LanguageModel. A resposta vem de NoEvidencePolicy, de forma determinística, e o diagnóstico registra modelCalled como false, promptCharacters igual a zero e tempo de modelo igual a zero.

O núcleo fornece FixedNoEvidencePolicy com mensagem configurável. O Bootstrap usa CONTEXT_ENGINE_NO_EVIDENCE_MESSAGE e oferece uma mensagem portuguesa por padrão. Isso evita depender da obediência do modelo para impedir alucinações quando o contexto está vazio.

No contrato incremental, ausência de evidência lança InsufficientContextException antes do provider. A biblioteca não transforma a resposta fixa em deltas simulados.
