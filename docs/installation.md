# Instalação

## Requisitos

- PHP 8.4 ou superior dentro da série aceita por `^8.4`;
- extensões `pdo` e `sockets`;
- driver `pdo_pgsql` para pgvector;
- `ext-redis` somente para o teste Redis fornecido;
- Composer 2;
- PostgreSQL com extensão pgvector para persistência real.

Dependências obrigatórias: QueryBuilder, HttpClient, FiberEventLoop, Utils e PSR-16. O [Bootstrap tipado](bootstrap.md) faz composição direta e não requer container PSR-11. `omegaalfa/collection` e `omegaalfa/lazy-object` são sugestões para composição na aplicação, não requisitos do núcleo.

## Instalação atual por VCS

A publicação de `omegaalfa/context-engine` no Packagist não foi confirmada. Não use `composer require` sem antes declarar os repositórios VCS. O `composer.json` completo e atual está em [Primeiros passos](getting-started.md#-7-instalando-a-biblioteca).

ContextEngine, QueryBuilder, HttpClient e FiberEventLoop precisam ser declarados em `repositories`; a constraint atual é `dev-main`, com `minimum-stability: dev`. Não há tag estável documentada.

## Desenvolvimento no monorepo

O repositório usa repositories Composer `path`:

```json
{
  "repositories": [
    {"type": "path", "url": "../query-builder"},
    {"type": "path", "url": "../HttpClient"},
    {"type": "path", "url": "../FiberEventLoop"}
  ]
}
```

```bash
composer install
composer dump-autoload --optimize
```

O autoload PSR-4 é `Omegaalfa\\ContextEngine\\` → `src/`.

## Ambiente

Copie apenas para uso local:

```bash
cp .env.example .env
docker compose --profile integration up -d --wait
```

Nunca versione `.env`. As variáveis e portas estão detalhadas em [docker-integration.md](docker-integration.md). O núcleo da biblioteca não lê configuração do ambiente. Os exemplos carregam o `.env` com `EnvLoader`; variáveis já fornecidas pelo processo, Docker, CI ou gerenciador de segredos têm precedência. `ContextEngineConfigFactory::fromEnvironment()` apenas lê o ambiente já carregado e nunca procura o arquivo automaticamente.
