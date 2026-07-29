# Testes e qualidade

```bash
composer validate --strict
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
vendor/bin/phpstan analyse --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
docker compose --env-file .env.example --profile integration config --quiet
```

Suites estão em `phpunit.xml.dist`. Integrações ficam em skip sem `CONTEXT_ENGINE_RUN_PGVECTOR_TESTS=1` ou `CONTEXT_ENGINE_RUN_REDIS_TESTS=1`; isso impede falhas acidentais em instalações sem serviços. Quando habilitadas, indisponibilidade/schema incorreto falham com mensagem clara.

PHPStan roda nível 9 em `src`. O teste arquitetural garante que `Future` não apareça fora da infraestrutura e que provider buffered não anuncie streaming. PHP CS Fixer aplica PSR-12/PHP 8.4.
