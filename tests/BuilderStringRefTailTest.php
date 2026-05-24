<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Builder::class)]
final class BuilderStringRefTailTest extends TestCase
{
    public function testEmptyStringRoundTrips(): void
    {
        $cell  = (new Builder())->storeStringRefTail('')->endCell();
        $slice = $cell->beginParse();
        self::assertSame('', $slice->loadStringRefTail());
    }

    public function testShortAsciiStringRoundTrips(): void
    {
        $payload = 'v1|src=TON|id=01HW2X3Y4Z5';
        $cell    = (new Builder())->storeStringRefTail($payload)->endCell();
        $slice   = $cell->beginParse();
        self::assertSame($payload, $slice->loadStringRefTail());
    }

    public function testHundredByteStringFitsInSingleRefHead(): void
    {
        $payload = str_repeat('a', 100);
        $cell    = (new Builder())->storeStringRefTail($payload)->endCell();
        $slice   = $cell->beginParse();
        $loaded  = $slice->loadStringRefTail();
        self::assertSame($payload, $loaded);
        self::assertSame(100, \strlen($loaded));
    }

    public function testThreeHundredByteStringSpillsToChainedRefs(): void
    {
        $payload = str_repeat('B', 300);
        $cell    = (new Builder())->storeStringRefTail($payload)->endCell();
        $slice   = $cell->beginParse();
        $loaded  = $slice->loadStringRefTail();
        self::assertSame($payload, $loaded);
        self::assertSame(300, \strlen($loaded));
    }

    public function testFiveHundredByteStringRoundTrips(): void
    {
        $payload = str_repeat('Z', 500);
        $cell    = (new Builder())->storeStringRefTail($payload)->endCell();
        $slice   = $cell->beginParse();
        $loaded  = $slice->loadStringRefTail();
        self::assertSame($payload, $loaded);
        self::assertSame(500, \strlen($loaded));
    }

    public function testStoreStringRefTailDoesNotOccupyParentCellBits(): void
    {
        $payload = str_repeat('x', 200);
        $cell    = (new Builder())
            ->storeUint(0xAA, 8)
            ->storeStringRefTail($payload)
            ->storeUint(0xBB, 8)
            ->endCell();

        $slice = $cell->beginParse();
        self::assertSame(0xAA, $slice->loadUint(8));
        self::assertSame($payload, $slice->loadStringRefTail());
        self::assertSame(0xBB, $slice->loadUint(8));
    }
}
