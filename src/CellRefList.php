<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Cell>
 */
final class CellRefList implements Countable, IteratorAggregate
{
    /**
     * @var list<Cell>
     */
    private array $cells = [];

    public function __construct(Cell ...$cells)
    {
        foreach ($cells as $cell) {
            $this->cells[] = $cell;
        }
    }

    public function add(Cell $cell): void
    {
        $this->cells[] = $cell;
    }

    public function get(int $index): ?Cell
    {
        return $this->cells[$index] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->cells;
    }

    public function count(): int
    {
        return \count($this->cells);
    }

    /**
     * @return Traversable<int, Cell>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cells);
    }

    /**
     * @return list<Cell>
     */
    public function toArray(): array
    {
        return $this->cells;
    }
}
