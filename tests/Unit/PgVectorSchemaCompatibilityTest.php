<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use Omegaalfa\ContextEngine\Embedding\Embedding;
use Omegaalfa\ContextEngine\Embedding\EmbeddingSpace;
use Omegaalfa\ContextEngine\Exception\IncompatibleVectorStoreSchemaException;
use Omegaalfa\ContextEngine\Retrieval\VectorSearchQuery;
use Omegaalfa\ContextEngine\VectorStore\PgVectorSchema;
use Omegaalfa\ContextEngine\VectorStore\PgVectorStore;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryResultDTO;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PgVectorSchemaCompatibilityTest extends TestCase
{
    public function testCompatibleSchemaExecutesSearchNormally(): void
    {
        $builder = $this->builderWithResult(new QueryResultDTO([
            [
                'distance' => 0.1,
                'chunk_id' => 'chunk-1',
                'document_id' => 'doc-1',
                'document_version' => 'version-1',
                'tenant_id' => 'tenant-a',
                'collection' => 'docs',
                'status' => 'active',
                'content' => 'hello',
                'position' => 0,
                'metadata' => '{}',
            ],
        ], 1));

        $store = new PgVectorStore($builder, new PgVectorSchema());
        $results = $store->search(new VectorSearchQuery(
            'tenant-a',
            new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
            collection: 'docs',
        ));

        self::assertCount(1, $results);
        self::assertSame('chunk-1', $results[0]->chunk->id);
    }

    public function testMissingRequiredColumnThrowsSpecificException(): void
    {
        $builder = $this->builderThrowing(new RuntimeException('SQLSTATE[42703]: column "version_status" does not exist'));

        $store = new PgVectorStore($builder, new PgVectorSchema());

        $this->expectException(IncompatibleVectorStoreSchemaException::class);
        $store->search(new VectorSearchQuery(
            'tenant-a',
            new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
            collection: 'docs',
        ));
    }

    public function testSchemaCompatibilityDetectionUsesSqlStateRegardlessOfLocale(): void
    {
        $builder = $this->builderThrowing(new class ('coluna "version_status" nao existe', 42703) extends RuntimeException {
            public function __construct(string $message, int $code)
            {
                parent::__construct($message, $code);
            }
        });

        $store = new PgVectorStore($builder, new PgVectorSchema());

        $this->expectException(IncompatibleVectorStoreSchemaException::class);
        $store->search(new VectorSearchQuery(
            'tenant-a',
            new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
            collection: 'docs',
        ));
    }

    public function testRealEmptyResultRemainsAnEmptyArray(): void
    {
        $builder = $this->builderWithResult(new QueryResultDTO([], 0));

        $store = new PgVectorStore($builder, new PgVectorSchema());
        $results = $store->search(new VectorSearchQuery(
            'tenant-a',
            new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
            collection: 'docs',
        ));

        self::assertSame([], $results);
    }

    public function testOriginalCauseIsPreserved(): void
    {
        $builder = $this->builderThrowing(new RuntimeException('SQLSTATE[42703]: column "version_status" does not exist'));

        $store = new PgVectorStore($builder, new PgVectorSchema());

        try {
            $store->search(new VectorSearchQuery(
                'tenant-a',
                new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
                collection: 'docs',
            ));
            self::fail('Expected exception.');
        } catch (IncompatibleVectorStoreSchemaException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(QueryException::class, $previous);
            self::assertSame('Query execution failed: SQLSTATE[42703]: column "version_status" does not exist', $previous->getMessage());
            self::assertInstanceOf(PDOException::class, $previous->getPrevious());
            self::assertSame('SQLSTATE[42703]: column "version_status" does not exist', $previous->getPrevious()->getMessage());
        }
    }

    public function testUnrelatedSqlErrorsAreNotConfusedWithSchemaIncompatibility(): void
    {
        $builder = $this->builderThrowing(new RuntimeException('connection refused'));

        $store = new PgVectorStore($builder, new PgVectorSchema());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query execution failed: connection refused');
        $store->search(new VectorSearchQuery(
            'tenant-a',
            new Embedding([0.1], new EmbeddingSpace('ollama', 'bge-m3', 1, 'space-a')),
            collection: 'docs',
        ));
    }

    private function builderWithResult(QueryResultDTO $result): QueryBuilder
    {
        $rows = [];
        foreach ($result->data as $row) {
            $rows[] = $row;
        }

        $stmt = new class ($rows) extends PDOStatement {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows = []) {}

            public function execute($params = null): bool
            {
                return true;
            }

            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
            {
                if ($this->rows === []) {
                    return false;
                }

                return array_shift($this->rows);
            }

            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                $rows = $this->rows;
                $this->rows = [];

                return $rows;
            }

            public function rowCount(): int
            {
                return count($this->rows);
            }

            public function closeCursor(): bool
            {
                return true;
            }

            public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
            {
                return true;
            }
        };

        $pdo = new class ($stmt) extends PDO {
            public function __construct(private PDOStatement $statement) {}

            public function prepare($query, $options = null): PDOStatement
            {
                return $this->statement;
            }
        };

        $connection = new class ($pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}

            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO
            {
                return $this->pdo;
            }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed
            {
                return $callback(null);
            }
            public function getDriver(): string
            {
                return 'pgsql';
            }
        };

        return new QueryBuilder($connection);
    }

    private function builderThrowing(Throwable $exception): QueryBuilder
    {
        $pdo = new class ($exception) extends PDO {
            public function __construct(private Throwable $exception) {}

            public function prepare($query, $options = null): PDOStatement
            {
                throw new PDOException($this->exception->getMessage(), (int) $this->exception->getCode());
            }
        };

        $connection = new class ($pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}

            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO
            {
                return $this->pdo;
            }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed
            {
                return $callback(null);
            }
            public function getDriver(): string
            {
                return 'pgsql';
            }
        };

        return new QueryBuilder($connection);
    }
}
