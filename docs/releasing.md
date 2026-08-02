# 🚀 Compatibilidade e processo de release

Este documento define como o ContextEngine é validado e preparado para publicação. Ele se destina a mantenedores; usuários da biblioteca devem começar pelo [guia de primeiros passos](getting-started.md).

## Matriz suportada

| Componente | Constraint | Papel |
|---|---:|---|
| PHP | `^8.4` | Runtime da biblioteca; CI cobre PHP 8.4 e 8.5. |
| `omegaalfa/query-builder` | `^2.0` | Persistência PostgreSQL/pgvector. |
| `omegaalfa/http-client` | `^1.0.1` | Transporte HTTP buffered dos adapters incluídos. |
| `omegaalfa/fiber-event-loop` | `^1.0` | Concorrência interna da infraestrutura. |
| `psr/simple-cache` | `^3.0` | Contrato dos decorators opcionais de cache. |

O manifesto distribuível não contém repositórios `path`, não habilita estabilidade `dev` e não depende de branches móveis. `omegaalfa/collection` e `omegaalfa/lazy-object` permanecem apenas em `suggest`.

## Política do lock file

O `composer.lock` permanece versionado para reproduzir a suíte deste repositório e registrar exatamente o conjunto validado no CI. Isso não fixa dependências nas aplicações consumidoras: ao instalar uma biblioteca, o Composer do projeto consumidor resolve as constraints do `composer.json`, não o lock publicado pela dependência.

## Pipeline obrigatório

Cada push e pull request executa:

1. `composer validate --strict`;
2. instalação por distribuição a partir dos repositórios públicos;
3. PHPUnit unitário em PHP 8.4 e 8.5;
4. PHPStan;
5. PHP-CS-Fixer em modo de verificação;
6. `composer audit --locked`;
7. integração real com PostgreSQL/pgvector e Redis em infraestrutura efêmera.

O job de integração provisiona o schema pela fixture versionada. A biblioteca nunca cria extensão, tabela ou índice durante sua execução normal.

## Checklist de uma versão

Antes de criar uma tag:

```bash
composer install --prefer-dist --no-interaction
composer validate --strict
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
composer audit --locked
docker compose --env-file .env.example --profile integration config --quiet
```

Também é necessário confirmar que o CI remoto está verde, revisar mudanças incompatíveis e atualizar documentação/changelog. Tags devem seguir versionamento semântico: correções compatíveis incrementam patch; funcionalidades compatíveis incrementam minor; quebras de API incrementam major.

## Estado de maturidade

Versões estáveis das dependências tornam a instalação determinística, mas não transformam automaticamente a engine em solução completa de produção. Antes desse rótulo ainda são relevantes, entre outros pontos, observabilidade integrada, políticas de retry/cancelamento e substituição atômica de versões completas de documentos.
