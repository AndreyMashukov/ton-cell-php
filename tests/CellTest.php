<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Builder;
use Amashukov\TonCell\Cell;
use Amashukov\TonCell\CellRefList;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TypeError;

#[CoversClass(Cell::class)]
final class CellTest extends TestCase
{
    public function testZeroBitCellHasNoBytesAndNoRefs(): void
    {
        $cell = new Cell('', 0);

        self::assertSame(0, $cell->bitLength);
        self::assertSame('', $cell->bits);
        self::assertTrue($cell->refs->isEmpty());
        self::assertSame(0, $cell->refs->count());
    }

    public function testHoldsRefsUpToFour(): void
    {
        $a = new Cell('', 0);
        $b = new Cell('', 0);
        $c = new Cell('', 0);
        $d = new Cell('', 0);

        $cell = new Cell('', 0, new CellRefList($a, $b, $c, $d));

        self::assertCount(4, $cell->refs);
    }

    public function testRejectsMoreThanFourRefs(): void
    {
        $r = new Cell('', 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('most 4 refs');

        new Cell('', 0, new CellRefList($r, $r, $r, $r, $r));
    }

    public function testCellConstructorRejectsNonCellRefViaPhpTypeSystem(): void
    {
        $this->expectException(TypeError::class);

        $reflection = new ReflectionClass(Cell::class);
        $reflection->newInstanceArgs(['', 0, ['not-a-cell']]);
    }

    public function testCellRefListRejectsNonCellAddViaPhpTypeSystem(): void
    {
        $list = new CellRefList();

        $this->expectException(TypeError::class);

        $method = new ReflectionMethod(CellRefList::class, 'add');
        $method->invoke($list, 'not-a-cell');
    }

    public function testRejectsNegativeBitLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cell('', -1);
    }

    public function testRejectsBitLengthAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cell(str_repeat("\x00", 128), 1024);
    }

    public function testRejectsBitsByteCountMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bits length mismatch');

        new Cell("\xff", 9);
    }

    public function testBeginParseReturnsFreshSliceEachCall(): void
    {
        $cell    = new Cell("\xff", 8);
        $sliceA  = $cell->beginParse();
        $sliceB  = $cell->beginParse();

        self::assertNotSame($sliceA, $sliceB);
        $sliceA->loadBit();
        self::assertSame(7, $sliceA->remainingBits());
        self::assertSame(8, $sliceB->remainingBits());
    }

    public function testHashEmptyCellMatchesReferenceImplementation(): void
    {
        $cell = (new Builder())->endCell();

        self::assertSame(
            '96a296d224f285c67bee93c30f8a309157f0daa35dc5b87e410b78630a09cfc7',
            bin2hex($cell->hash()),
        );
        self::assertSame(0, $cell->maxDepth());
    }

    public function testHashUint8MatchesReferenceImplementation(): void
    {
        $cell = (new Builder())->storeUint(0xA5, 8)->endCell();

        self::assertSame(
            'abcf2d127ff19e8bd47d4c5788cf3049a51766abeaabf05df205993c3bc235ec',
            bin2hex($cell->hash()),
        );
    }

    public function testHashUint32BigintMatchesReferenceImplementation(): void
    {
        $cell = (new Builder())->storeUint(0xDEADBEEF, 32)->endCell();

        self::assertSame(
            '270906fd171b9c43f37a353059a73fbc02e0568188ec30186af846caefd09b8c',
            bin2hex($cell->hash()),
        );
    }

    public function testHashAddressMatchesReferenceImplementation(): void
    {
        $addr = new AddressData(0, hex2bin('83dfd552e63729b472fcbcc8c45ebcc6691702558b68ec7527e1ba403a0f31a8') ?: '');
        $cell = (new Builder())->storeAddress($addr)->endCell();

        self::assertSame(
            '58e2d2fc9446d00e70b3ad3ea1eb88797c323bae517f2527f807f62d09a56fdc',
            bin2hex($cell->hash()),
        );
    }

    public function testHashWithSingleRefMatchesReferenceImplementation(): void
    {
        $child  = (new Builder())->storeUint(0xDEADBEEF, 32)->endCell();
        $parent = (new Builder())->storeUint(1, 8)->storeRef($child)->endCell();

        self::assertSame(
            'eb6a640de83d3812457e5eea8c0979edc3260add28ba5edccc9ea075721462a5',
            bin2hex($parent->hash()),
        );
        self::assertSame(1, $parent->maxDepth());
        self::assertSame(0, $child->maxDepth());
    }

    public function testHashTwoLevelChainMatchesReferenceImplementation(): void
    {
        $gc  = (new Builder())->storeUint(0xFF, 8)->endCell();
        $mid = (new Builder())->storeUint(0xEE, 8)->storeRef($gc)->endCell();
        $top = (new Builder())->storeUint(0xDD, 8)->storeRef($mid)->endCell();

        self::assertSame(
            '573d1ba51eaaf69946e348f3c4132a50135d44f50972bd71ace4cd4d63f5ee7c',
            bin2hex($top->hash()),
        );
        self::assertSame(
            'f33f0c72b6488159f64104e37fa4e7f8b72bf1f2f1d227b32928a73a0c0e67a2',
            bin2hex($mid->hash()),
        );
        self::assertSame(
            '81f3b92f222078b1606cfc3eebfee22216cc40ac99e6524b00fbaa933a6bcd47',
            bin2hex($gc->hash()),
        );

        self::assertSame(2, $top->maxDepth());
        self::assertSame(1, $mid->maxDepth());
        self::assertSame(0, $gc->maxDepth());
    }

    public function testHashIsMemoizedAcrossCalls(): void
    {
        $cell = (new Builder())->storeUint(0xA5, 8)->endCell();
        $h1   = $cell->hash();
        $h2   = $cell->hash();

        self::assertSame($h1, $h2);
    }

    public function testMaxDepthOfBranchedTree(): void
    {
        $leaf       = (new Builder())->storeUint(0, 1)->endCell();
        $oneDeep    = (new Builder())->storeRef($leaf)->endCell();
        $twoDeep    = (new Builder())->storeRef($oneDeep)->endCell();
        $branchTop  = (new Builder())
            ->storeRef($leaf)
            ->storeRef($oneDeep)
            ->storeRef($twoDeep)
            ->endCell();

        self::assertSame(3, $branchTop->maxDepth());
    }
}
