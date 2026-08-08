<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Omegaalfa\ContextEngine\Bootstrap\ContextEngineConfigFactory;
use Omegaalfa\ContextEngine\ContextEngine;
use Omegaalfa\ContextEngine\Contract\LexicalSearchStore;
use Omegaalfa\ContextEngine\Loader\TextFileGranularity;
use Omegaalfa\ContextEngine\Loader\TextFileLoader;
use Omegaalfa\ContextEngine\Retrieval\LexicalSearchQuery;
use Omegaalfa\ContextEngine\Retrieval\RetrievalPolicy;

const TENANT = 'evaluation-lexical-language-live';
const COLLECTION = 'evaluation-lexical-language-live';

try {
    $config = ContextEngineConfigFactory::fromEnvironment();
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $config->database->host, $config->database->port, $config->database->database),
        $config->database->username,
        $config->database->password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $text = "Running algorithms efficiently requires tested implementations.";
    $query = 'runs algorithm';
    $native = [];
    foreach (['english', 'simple'] as $language) {
        $statement = $pdo->prepare("SELECT to_tsvector('{$language}', ?) @@ websearch_to_tsquery('{$language}', ?) AS matched");
        $statement->execute([$text, $query]);
        $native[$language] = filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL);
    }

    $context = ContextEngine::create()->tenant(TENANT)->collection(COLLECTION)->build();
    $context->ingest(new TextFileLoader(
        dirname(__DIR__).'/documents/algorithms-english.md',
        TENANT,
        COLLECTION,
        granularity: TextFileGranularity::WHOLE_FILE,
    ));
    if (!$context->store instanceof LexicalSearchStore) {
        throw new RuntimeException('O vector store configurado não oferece busca lexical.');
    }
    $actual = [];
    foreach (['english', 'simple'] as $language) {
        $actual[$language] = $context->store->searchLexical(new LexicalSearchQuery(
            TENANT,
            $query,
            new RetrievalPolicy(10),
            COLLECTION,
            textSearchConfiguration: $language,
        ));
    }

    echo PHP_EOL.str_repeat('=', 82).PHP_EOL.'  ContextEngine - PostgreSQL Lexical Language'.PHP_EOL.str_repeat('=', 82).PHP_EOL;
    echo 'Corpus: examples/documents/algorithms-english.md'.PHP_EOL;
    echo 'Query: '.$query.PHP_EOL.PHP_EOL;
    echo sprintf("%-16s %-24s %-24s\n", 'Configuração', 'PostgreSQL alinhado', 'search_vector atual');
    echo str_repeat('-', 70).PHP_EOL;
    foreach (['english', 'simple'] as $language) {
        echo sprintf("%-16s %-24s %-24s\n", $language, $native[$language] ? 'MATCH' : 'NO MATCH', $actual[$language] === [] ? 'NO MATCH' : 'MATCH');
    }
    echo PHP_EOL.'Interpretação: `english` aplica stemming; `simple` exige formas textuais mais próximas.'.PHP_EOL;
    echo 'O schema padrão gera search_vector com `portuguese`. Para outro idioma, provisione a coluna'.PHP_EOL;
    echo 'com o mesmo dicionário usado em textSearchConfiguration; mudar apenas a query não reindexa dados.'.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Lexical language live indisponível: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
