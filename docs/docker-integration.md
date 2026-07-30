# Docker e integração

## Controle rápido dos serviços

O script executável `context-engine.sh` abre um painel visual com opções numeradas. Basta executar e escolher no menu:

```bash
./context-engine.sh
```

Os comandos diretos continuam disponíveis como atalhos opcionais:

```bash
./context-engine.sh up              # sobe tudo e aguarda saúde
./context-engine.sh up pgvector     # sobe somente PostgreSQL/pgvector
./context-engine.sh stop            # para sem remover containers
./context-engine.sh restart redis   # recria somente Redis
./context-engine.sh status          # mostra estado e healthcheck
./context-engine.sh logs pgvector   # acompanha logs
./context-engine.sh down            # remove containers/rede, preserva volumes
./context-engine.sh config          # valida/exibe o Compose resolvido
```

Ele usa `.env` quando presente e recorre a `.env.example` sem criar arquivos. Para outro arquivo:

```bash
CONTEXT_ENGINE_ENV_FILE=/caminho/servicos.env ./context-engine.sh up
```

`down` não passa `--volumes`; os dados persistentes são preservados.

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

Portas padrão: PostgreSQL `54339`, Redis `63809`. Em colisão:

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
