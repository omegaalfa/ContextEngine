# Instalação

## Requisitos

- PHP 8.4 ou superior dentro da série aceita por `^8.4`;
- extensões `pdo` e `sockets`;
- driver `pdo_pgsql` para pgvector;
- `ext-redis` somente para o teste Redis fornecido;
- Composer 2;
- PostgreSQL com extensão pgvector para persistência real.

Dependências obrigatórias: QueryBuilder, HttpClient, FiberEventLoop e PSR-16. `omegaalfa/collection` e `omegaalfa/lazy-object` são sugestões para composição na aplicação, não requisitos do núcleo.

## Packagist

```bash
composer require omegaalfa/context-engine
```

Enquanto os pacotes Omegaalfa estiverem em `dev-main`, pode ser necessário permitir estabilidade dev no projeto consumidor. Quando publicados de forma estável, o consumidor não precisará dos repositories locais.

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

Nunca versione `.env`. As variáveis e portas estão detalhadas em [docker-integration.md](docker-integration.md). A biblioteca não lê automaticamente essas variáveis; elas configuram Compose/testes ou devem ser lidas pela aplicação ao construir dependências.
