<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Builder;
use Amashukov\TonCell\Cell;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Builder::class)]
final class BuilderTest extends TestCase
{
    public function testEmptyBuilderEndsWithZeroBitsAndNoRefs(): void
    {
        $cell = (new Builder())->endCell();

        self::assertSame(0, $cell->bitLength);
        self::assertSame('', $cell->bits);
        self::assertSame([], $cell->refs->toArray());
    }

    public function testStoreBitFalseProducesZeroByte(): void
    {
        $cell = (new Builder())
            ->storeBit(false)->storeBit(false)->storeBit(false)->storeBit(false)
            ->storeBit(false)->storeBit(false)->storeBit(false)->storeBit(false)
            ->endCell();

        self::assertSame(8, $cell->bitLength);
        self::assertSame("\x00", $cell->bits);
    }

    public function testStoreBitTrueProducesAllOnesByte(): void
    {
        $b = new Builder();
        for ($i = 0; $i < 8; ++$i) {
            $b->storeBit(true);
        }
        $cell = $b->endCell();

        self::assertSame(8, $cell->bitLength);
        self::assertSame("\xff", $cell->bits);
    }

    public function testStoreBitMsbFirstWithinByte(): void
    {
        $cell = (new Builder())->storeBit(true)
            ->storeBit(false)->storeBit(false)->storeBit(false)
            ->storeBit(false)->storeBit(false)->storeBit(false)->storeBit(false)
            ->endCell();

        self::assertSame("\x80", $cell->bits);
    }

    public function testStoreUintEightBits(): void
    {
        $cell = (new Builder())->storeUint(0xA5, 8)->endCell();

        self::assertSame(8, $cell->bitLength);
        self::assertSame("\xa5", $cell->bits);
    }

    public function testStoreUintZeroPadsHighBits(): void
    {
        $cell = (new Builder())->storeUint(1, 16)->endCell();

        self::assertSame("\x00\x01", $cell->bits);
    }

    public function testStoreUintBigintAccepts256BitValueAsString(): void
    {
        $maxU256 = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
        $cell    = (new Builder())->storeUint($maxU256, 256)->endCell();

        self::assertSame(256, $cell->bitLength);
        self::assertSame(str_repeat("\xff", 32), $cell->bits);
    }

    public function testStoreUintRejectsValueExceedingBitWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not fit in 8 bits');

        (new Builder())->storeUint(256, 8);
    }

    public function testStoreUintRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Builder())->storeUint('-1', 8);
    }

    public function testStoreUintRejectsBitsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Builder())->storeUint(0, 257);
    }

    public function testStoreCoinsZeroProducesFourZeroBits(): void
    {
        $cell = (new Builder())->storeCoins(0)->endCell();

        self::assertSame(4, $cell->bitLength);
        self::assertSame("\x00", $cell->bits);
    }

    public function testStoreCoinsSmallValueLengthIsByteCount(): void
    {
        $cell = (new Builder())->storeCoins(255)->endCell();

        self::assertSame(12, $cell->bitLength);
        self::assertSame("\x1f\xf0", $cell->bits);
    }

    public function testStoreCoinsFifteenByteMaxLength(): void
    {
        $u120Max = '1329227995784915872903807060280344575';
        $cell    = (new Builder())->storeCoins($u120Max)->endCell();

        self::assertSame(124, $cell->bitLength);
    }

    public function testStoreCoinsRejectsValueRequiringSixteenBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        $oversize = '0x' . str_repeat('ff', 16);
        (new Builder())->storeCoins($oversize);
    }

    public function testStoreAddressNullEncodesAsTwoZeroBits(): void
    {
        $cell = (new Builder())->storeAddress(null)->endCell();

        self::assertSame(2, $cell->bitLength);
        self::assertSame("\x00", $cell->bits);
    }

    public function testStoreAddressStandardFormProduces267Bits(): void
    {
        $hash = str_repeat("\xab", 32);
        $cell = (new Builder())
            ->storeAddress(new AddressData(0, $hash))
            ->endCell();

        self::assertSame(267, $cell->bitLength);
        self::assertSame("\x80", $cell->bits[0]);
    }

    public function testStoreAddressMasterchainEncodesAsFf(): void
    {
        $hash = str_repeat("\x00", 32);
        $cell = (new Builder())
            ->storeAddress(new AddressData(-1, $hash))
            ->endCell();

        self::assertSame("\x9f", $cell->bits[0]);
        self::assertSame("\xe0", $cell->bits[1]);
    }

    public function testStoreRefAccumulatesUpToFour(): void
    {
        $b = new Builder();
        $b->storeRef(new Cell('', 0))
            ->storeRef(new Cell('', 0))
            ->storeRef(new Cell('', 0))
            ->storeRef(new Cell('', 0));

        self::assertSame(4, $b->refs());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum of 4 refs');

        $b->storeRef(new Cell('', 0));
    }

    public function testStoreBitsCopiesRawBinaryMsbFirst(): void
    {
        $cell = (new Builder())->storeBits("\xa5", 8)->endCell();

        self::assertSame(8, $cell->bitLength);
        self::assertSame("\xa5", $cell->bits);
    }

    public function testStoreBitsRejectsByteCountMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Builder())->storeBits("\x00", 16);
    }

    public function testStoreStringTailEncodesAsciiInPlace(): void
    {
        $cell = (new Builder())
            ->storeUint(0, 32)
            ->storeStringTail('memo1')
            ->endCell();

        self::assertSame(72, $cell->bitLength);
        self::assertSame("\x00\x00\x00\x00memo1", $cell->bits);
        self::assertSame([], $cell->refs->toArray());
    }

    public function testStoreStringTailOverflowsIntoChainedRef(): void
    {
        $b = new Builder();
        for ($i = 0; $i < 1000; ++$i) {
            $b->storeBit(false);
        }
        $longString = str_repeat('X', 30);
        $cell       = $b->storeStringTail($longString)->endCell();

        self::assertCount(1, $cell->refs);
        $tailCell = $cell->refs->toArray()[0];
        self::assertSame(28 * 8, $tailCell->bitLength);
    }

    public function testEndCellPaddingZeroFillsTrailingPartialByte(): void
    {
        $cell = (new Builder())
            ->storeBit(true)->storeBit(true)->storeBit(true)->storeBit(true)->storeBit(true)
            ->endCell();

        self::assertSame(5, $cell->bitLength);
        self::assertSame("\xf8", $cell->bits);
    }

    public function testStoreBufferAppendsRawBytesAsBits(): void
    {
        $cell = (new Builder())->storeBuffer("\xab\xcd")->endCell();

        self::assertSame(16, $cell->bitLength);
        self::assertSame("\xab\xcd", $cell->bits);
    }

    public function testStoreBuilderAppendsBitsAndRefs(): void
    {
        $childA = (new Builder())->storeUint(0x11, 8)->endCell();
        $childB = (new Builder())->storeUint(0x22, 8)->endCell();

        $inner = (new Builder())
            ->storeUint(0xCAFE, 16)
            ->storeRef($childA);

        $outer = (new Builder())
            ->storeUint(0xBE, 8)
            ->storeBuilder($inner)
            ->storeRef($childB)
            ->endCell();

        self::assertSame(8 + 16, $outer->bitLength);
        self::assertCount(2, $outer->refs);
        self::assertSame(0x11, $outer->refs->toArray()[0]->beginParse()->loadUint(8));
        self::assertSame(0x22, $outer->refs->toArray()[1]->beginParse()->loadUint(8));
    }

    public function testStoreCellInlineSplatBitsAndRefs(): void
    {
        $grandchild   = (new Builder())->storeUint(0x99, 8)->endCell();
        $cellToInline = (new Builder())
            ->storeUint(0xABCD, 16)
            ->storeRef($grandchild)
            ->endCell();

        $outer = (new Builder())
            ->storeUint(1, 1)
            ->storeCellInline($cellToInline)
            ->endCell();

        self::assertSame(1 + 16, $outer->bitLength);
        self::assertCount(1, $outer->refs);

        $slice = $outer->beginParse();
        self::assertTrue($slice->loadBit());
        self::assertSame(0xABCD, $slice->loadUint(16));
        self::assertSame(0x99, $slice->loadRef()->beginParse()->loadUint(8));
    }

    public function testStoreCellInlineRejectsCombinedRefOverflow(): void
    {
        $r           = new Cell('', 0);
        $hasThree    = (new Builder())->storeRef($r)->storeRef($r)->storeRef($r);
        $cellWithTwo = (new Builder())->storeRef($r)->storeRef($r)->endCell();

        $this->expectException(InvalidArgumentException::class);

        $hasThree->storeCellInline($cellWithTwo);
    }

    public function testEnsureCapacityRejectsOverflowToCellMaxBits(): void
    {
        $b = new Builder();
        $b->storeUint(0, 256)->storeUint(0, 256)->storeUint(0, 256)->storeUint(0, 255);
        self::assertSame(1023, $b->bits());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('overflow');

        $b->storeBit(true);
    }

    public function testStoreIntPositiveValueStoresSameBitsAsUint(): void
    {
        $cell = (new Builder())->storeInt(42, 8)->endCell();

        self::assertSame(42, $cell->beginParse()->loadUint(8));
    }

    public function testStoreIntNegativeValueUsesTwoComplement(): void
    {
        $cell = (new Builder())->storeInt(-1, 8)->endCell();
        self::assertSame(255, $cell->beginParse()->loadUint(8));

        $cell2 = (new Builder())->storeInt(-42, 8)->endCell();
        self::assertSame(214, $cell2->beginParse()->loadUint(8));
    }

    public function testStoreIntBoundaryValuesForEightBits(): void
    {
        $minCell = (new Builder())->storeInt(-128, 8)->endCell();
        $maxCell = (new Builder())->storeInt(127, 8)->endCell();

        self::assertSame(128, $minCell->beginParse()->loadUint(8));
        self::assertSame(127, $maxCell->beginParse()->loadUint(8));
    }

    public function testStoreIntRejectsValueExceedingSignedRange(): void
    {
        $b = new Builder();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not fit in 8 signed bits/');

        $b->storeInt(128, 8);
    }

    public function testStoreIntRejectsNegativeUnderflow(): void
    {
        $b = new Builder();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not fit in 8 signed bits/');

        $b->storeInt(-129, 8);
    }

    public function testStoreIntRejectsBitsOutOfRange(): void
    {
        $b = new Builder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storeInt bits must be 1..257');

        $b->storeInt(0, 258);
    }

    public function testStoreIntRejectsZeroBits(): void
    {
        $b = new Builder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storeInt bits must be 1..257');

        $b->storeInt(0, 0);
    }

    public function testStoreStringRefTailWrapsStringInChildRef(): void
    {
        $b    = (new Builder())->storeStringRefTail('hello');
        $cell = $b->endCell();

        self::assertSame(0, $cell->bitLength, 'parent has no inline bits');
        self::assertCount(1, $cell->refs, 'parent has exactly one ref');
        self::assertSame('hello', $cell->refs->toArray()[0]->beginParse()->loadStringTail());
    }

    public function testStoreStringRefTailEmptyStringStillAllocatesRef(): void
    {
        $b    = (new Builder())->storeStringRefTail('');
        $cell = $b->endCell();

        self::assertSame(0, $cell->bitLength);
        self::assertCount(1, $cell->refs);
        self::assertSame(0, $cell->refs->toArray()[0]->bitLength, 'empty payload -> empty child cell');
    }

    public function testStoreCoinsRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storeCoins value must be non-negative');

        (new Builder())->storeCoins('-1');
    }

    public function testStoreCoinsPadsOddLengthHexToWholeByte(): void
    {
        $cell = (new Builder())->storeCoins(15)->endCell();
        self::assertSame(12, $cell->bitLength);
        self::assertSame('15', $cell->beginParse()->loadCoins());
    }

    public function testStoreBuilderRejectsCombinedRefsAboveMaxFour(): void
    {
        $emptyChild = (new Builder())->endCell();
        $parent     = (new Builder())
            ->storeRef($emptyChild)
            ->storeRef($emptyChild)
            ->storeRef($emptyChild)
        ;
        $other = (new Builder())
            ->storeRef($emptyChild)
            ->storeRef($emptyChild)
        ;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storeBuilder: combined refs 3 + 2 > max 4');

        $parent->storeBuilder($other);
    }
}
