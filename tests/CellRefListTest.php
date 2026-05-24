<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\Builder;
use Amashukov\TonCell\Cell;
use Amashukov\TonCell\CellRefList;
use Countable;
use IteratorAggregate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TypeError;

#[CoversClass(CellRefList::class)]
final class CellRefListTest extends TestCase
{
    public function testImplementsCountableAndIteratorAggregate(): void
    {
        $list = new CellRefList();
        self::assertInstanceOf(Countable::class, $list);
        self::assertInstanceOf(IteratorAggregate::class, $list);
    }

    public function testFreshListIsEmpty(): void
    {
        $list = new CellRefList();
        self::assertTrue($list->isEmpty());
        self::assertSame(0, $list->count());
        self::assertSame([], $list->toArray());
    }

    public function testVariadicCtorStoresCellsInArgumentOrder(): void
    {
        $a = (new Builder())->storeUint(1, 8)->endCell();
        $b = (new Builder())->storeUint(2, 8)->endCell();
        $c = (new Builder())->storeUint(3, 8)->endCell();

        $list = new CellRefList($a, $b, $c);

        self::assertSame(3, $list->count());
        self::assertSame($a, $list->get(0));
        self::assertSame($b, $list->get(1));
        self::assertSame($c, $list->get(2));
        self::assertSame([$a, $b, $c], $list->toArray());
    }

    public function testAddAppendsCellInOrder(): void
    {
        $list = new CellRefList();
        $a    = (new Builder())->storeUint(1, 8)->endCell();
        $b    = (new Builder())->storeUint(2, 8)->endCell();
        $c    = (new Builder())->storeUint(3, 8)->endCell();

        $list->add($a);
        $list->add($b);
        $list->add($c);

        self::assertSame(3, $list->count());
        self::assertSame($a, $list->get(0));
        self::assertSame($b, $list->get(1));
        self::assertSame($c, $list->get(2));
    }

    public function testIterationYieldsCellsInInsertionOrder(): void
    {
        $cells = [
            (new Builder())->storeUint(10, 8)->endCell(),
            (new Builder())->storeUint(20, 8)->endCell(),
            (new Builder())->storeUint(30, 8)->endCell(),
        ];
        $list = new CellRefList(...$cells);

        $seen = [];
        foreach ($list as $cell) {
            self::assertInstanceOf(Cell::class, $cell);
            $seen[] = $cell;
        }
        self::assertSame($cells, $seen);
    }

    public function testGetOutOfRangeReturnsNull(): void
    {
        $list = new CellRefList((new Builder())->endCell());

        self::assertNull($list->get(99));
        self::assertNull($list->get(-1));
    }

    public function testAddRejectsNonCellAtThePhpTypeBoundary(): void
    {
        $list = new CellRefList();

        $this->expectException(TypeError::class);

        $method = new ReflectionMethod(CellRefList::class, 'add');
        $method->invoke($list, 'not-a-cell');
    }

    public function testCtorRejectsNonCellAtThePhpTypeBoundary(): void
    {
        $this->expectException(TypeError::class);

        $reflection = new ReflectionClass(CellRefList::class);
        $reflection->newInstanceArgs(['not-a-cell']);
    }
}
