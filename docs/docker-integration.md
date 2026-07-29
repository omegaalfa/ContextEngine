# Docker e integração

O Compose opt-in contém apenas pgvector e Redis, com imagens/digests fixos, healthchecks, rede e volumes próprios.

```bash
cp .env.example .env
docker compose --env-file .env.example --profile integration up -d --wait
docker compose --env-file .env.example --profile integration down
```

Somente pgvector:

```bash
docker compose --env-file .env.example --profile integration up -d --wait pgvector
```

Portas padrão: PostgreSQL `54329`, Redis `63799`. Em colisão:

```bash
CONTEXT_ENGINE_PGVECTOR_PORT=54339 \
CONTEXT_ENGINE_REDIS_PORT=63809 \
docker compose --env-file .env.example --profile integration up -d --wait
```

Volumes persistem após `down`. `down -v` apaga ambos; para recriar só PostgreSQL, pare/remova o serviço e remova `context-engine_context_engine_pgvector_data` explicitamente. Essa ação apaga dados de integração.

Flags:

```bash
CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1 vendor/bin/phpunit --testsuite integration --filter PgVectorIntegrationTest
CONTEXT_ENGINE_RUN_REDIS_TESTS=1 vendor/bin/phpunit --testsuite integration --filter RedisCacheIntegrationTest
```

A fixture `tests/Integration/Fixtures/postgresql/schema.sql` cria extensão/tabela somente no ambiente do container; runtime da biblioteca nunca executa DDL.
