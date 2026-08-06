<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Evaluation;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, EvaluationCase> */
final readonly class EvaluationDataset implements Countable, IteratorAggregate
{
    /** @var list<EvaluationCase> */
    public array $cases;

    /** @param list<EvaluationCase> $cases */
    public function __construct(array $cases, public string $name = 'Dataset')
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Evaluation dataset name cannot be empty.');
        }
        $ids = array_map(static fn (EvaluationCase $case): string => $case->id, $cases);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Evaluation case ids must be unique.');
        }
        $this->cases = $cases;
    }

    public function count(): int
    {
        return count($this->cases);
    }

    /** @return Traversable<int, EvaluationCase> */
    public function getIterator(): Traversable
    {
        yield from $this->cases;
    }
}
