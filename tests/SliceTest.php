<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Builder;
use Amashukov\TonCell\Cell;
use Amashukov\TonCell\Slice;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Slice::class)]
final class SliceTest extends TestCase
{
    public function testRoundtripBitOrderingMsbFirst(): void
    {
        $cell  = (new Builder())
            ->storeBit(true)->storeBit(false)->storeBit(true)->storeBit(true)
            ->endCell();
        $slice = $cell->beginParse();

        self::assertTrue($slice->loadBit());
        self::assertFalse($slice->loadBit());
        self::assertTrue($slice->loadBit());
        self::assertTrue($slice->loadBit());
        self::assertSame(0, $slice->remainingBits());
    }

    public function testRoundtripUintEightBits(): void
    {
        $cell = (new Builder())->storeUint(0xA5, 8)->endCell();

        self::assertSame(0xA5, $cell->beginParse()->loadUint(8));
    }

    public function testRoundtripUintCrossesByteBoundary(): void
    {
        $cell = (new Builder())->storeUint(0xABC, 12)->endCell();

        self::assertSame(0xABC, $cell->beginParse()->loadUint(12));
    }

    public function testRoundtripUint256BigintReturnsString(): void
    {
        $maxU256 = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
        $cell    = (new Builder())->storeUint($maxU256, 256)->endCell();

        $value = $cell->beginParse()->loadUint(256);

        self::assertSame($maxU256, $value);
    }

    public function testLoadUintFitsInIntForSmallValues(): void
    {
        $cell = (new Builder())->storeUint(42, 32)->endCell();
        self::assertSame(42, $cell->beginParse()->loadUint(32));
    }

    public function testLoadUintStringAlwaysReturnsString(): void
    {
        $cell = (new Builder())->storeUint(42, 32)->endCell();
        self::assertSame('42', $cell->beginParse()->loadUintString(32));
    }

    public function testRoundtripCoinsZero(): void
    {
        $cell = (new Builder())->storeCoins(0)->endCell();

        self::assertSame('0', $cell->beginParse()->loadCoins());
    }

    public function testRoundtripCoinsSmallValue(): void
    {
        $cell = (new Builder())->storeCoins(255)->endCell();

        self::assertSame('255', $cell->beginParse()->loadCoins());
    }

    public function testRoundtripCoinsLargeValue(): void
    {
        $cell = (new Builder())->storeCoins('100000000000')->endCell();

        self::assertSame('100000000000', $cell->beginParse()->loadCoins());
    }

    public function testRoundtripCoinsValuePastPhpIntMax(): void
    {
        $big  = '18000000000000000000';
        $cell = (new Builder())->storeCoins($big)->endCell();

        self::assertSame($big, $cell->beginParse()->loadCoins());
    }

    public function testRoundtripAddressNullProducesNull(): void
    {
        $cell = (new Builder())->storeAddress(null)->endCell();

        self::assertNull($cell->beginParse()->loadAddress());
    }

    public function testRoundtripAddressStandardForm(): void
    {
        $hash = str_repeat("\xab", 32);
        $orig = new AddressData(0, $hash);

        $cell     = (new Builder())->storeAddress($orig)->endCell();
        $reparsed = $cell->beginParse()->loadAddress();

        if (!$reparsed instanceof AddressData) {
            self::fail('roundtrip of a non-null address must not yield null');
        }
        self::assertSame(0, $reparsed->wc);
        self::assertSame($hash, $reparsed->hashPart);
    }

    public function testRoundtripAddressMasterchain(): void
    {
        $hash    = random_bytes(32);
        $orig    = new AddressData(-1, $hash);
        $cell    = (new Builder())->storeAddress($orig)->endCell();
        $loaded  = $cell->beginParse()->loadAddress();

        if (!$loaded instanceof AddressData) {
            self::fail('roundtrip of a masterchain address must not yield null');
        }
        self::assertSame(-1, $loaded->wc);
        self::assertSame($hash, $loaded->hashPart);
    }

    public function testLoadAddressRejectsAnycastForm(): void
    {
        $cell  = (new Builder())
            ->storeUint(2, 2)
            ->storeBit(true)
            ->storeUint(0, 8)
            ->storeBits(str_repeat("\x00", 32), 256)
            ->endCell();
        $slice = $cell->beginParse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anycast');

        $slice->loadAddress();
    }

    public function testLoadAddressRejectsUnsupportedTag(): void
    {
        $cell = (new Builder())->storeUint(1, 2)->endCell();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('address tag');

        $cell->beginParse()->loadAddress();
    }

    public function testRefRoundtripFifoOrdering(): void
    {
        $childA = (new Builder())->storeUint(0xAA, 8)->endCell();
        $childB = (new Builder())->storeUint(0xBB, 8)->endCell();

        $parent = (new Builder())->storeRef($childA)->storeRef($childB)->endCell();
        $slice  = $parent->beginParse();

        self::assertSame(2, $slice->remainingRefs());
        $first = $slice->loadRef();
        self::assertSame(0xAA, $first->beginParse()->loadUint(8));
        $second = $slice->loadRef();
        self::assertSame(0xBB, $second->beginParse()->loadUint(8));
        self::assertSame(0, $slice->remainingRefs());
    }

    public function testLoadRefThrowsWhenExhausted(): void
    {
        $cell  = (new Builder())->endCell();
        $slice = $cell->beginParse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no more refs');

        $slice->loadRef();
    }

    public function testLoadStringTailReadsHeadOnly(): void
    {
        $cell = (new Builder())
            ->storeUint(0, 32)
            ->storeStringTail('memo1')
            ->endCell();

        $slice = $cell->beginParse();
        $op    = $slice->loadUint(32);
        self::assertSame(0, $op);
        self::assertSame('memo1', $slice->loadStringTail());
    }

    public function testLoadStringTailWalksChainedRefs(): void
    {
        $b = new Builder();
        for ($i = 0; $i < 1000; ++$i) {
            $b->storeBit(false);
        }
        $orig = str_repeat('X', 30);
        $cell = $b->storeStringTail($orig)->endCell();

        $slice = $cell->beginParse();
        for ($i = 0; $i < 1000; ++$i) {
            $slice->loadBit();
        }

        self::assertSame($orig, $slice->loadStringTail());
    }

    public function testLoadStringTailRejectsNonByteAlignedTail(): void
    {
        $cell = (new Builder())
            ->storeBit(true)->storeBit(false)->storeBit(true)
            ->storeBit(true)->storeBit(false)->storeBit(true)->storeBit(false)
            ->endCell();

        $slice = $cell->beginParse();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not byte-aligned');

        $slice->loadStringTail();
    }

    public function testLoadBitsReturnsExactByteString(): void
    {
        $cell  = (new Builder())->storeBits("\xde\xad\xbe\xef", 32)->endCell();
        $slice = $cell->beginParse();

        self::assertSame("\xde\xad\xbe\xef", $slice->loadBits(32));
    }

    public function testLoadBitsZeroPadsLastPartialByte(): void
    {
        $cell = (new Builder())->storeUint(0xABC, 12)->endCell();

        self::assertSame("\xab\xc0", $cell->beginParse()->loadBits(12));
    }

    public function testRemainingBitsTracksConsumption(): void
    {
        $cell  = (new Builder())->storeUint(0, 16)->endCell();
        $slice = $cell->beginParse();

        self::assertSame(16, $slice->remainingBits());
        $slice->loadUint(8);
        self::assertSame(8, $slice->remainingBits());
        $slice->loadUint(8);
        self::assertSame(0, $slice->remainingBits());
    }

    public function testLoadBitUnderflowThrows(): void
    {
        $cell  = new Cell('', 0);
        $slice = $cell->beginParse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('underflow');

        $slice->loadBit();
    }

    public function testLoadUintBitsOutOfRangeThrows(): void
    {
        $cell  = new Cell("\x00", 8);
        $slice = $cell->beginParse();

        $this->expectException(InvalidArgumentException::class);

        $slice->loadUint(0);
    }

    public function testJettonTransferBodyShapeRoundtrip(): void
    {
        $recipient = new AddressData(0, str_repeat("\x42", 32));
        $cell      = (new Builder())
            ->storeUint(0xF8A7EA5, 32)
            ->storeUint(0xC0FFEE, 64)
            ->storeCoins('1000000')
            ->storeAddress($recipient)
            ->storeAddress($recipient)
            ->storeBit(false)
            ->storeCoins('20000000')
            ->storeBit(false)
            ->endCell();

        $s = $cell->beginParse();
        self::assertSame(0xF8A7EA5, $s->loadUint(32));
        self::assertSame(0xC0FFEE, $s->loadUint(64));
        self::assertSame('1000000', $s->loadCoins());
        $loadedDest = $s->loadAddress();
        if (!$loadedDest instanceof AddressData) {
            self::fail('non-null destination must roundtrip');
        }
        self::assertSame($recipient->hashPart, $loadedDest->hashPart);
        $loadedResp = $s->loadAddress();
        if (!$loadedResp instanceof AddressData) {
            self::fail('non-null response_destination must roundtrip');
        }
        self::assertSame($recipient->hashPart, $loadedResp->hashPart);
        self::assertFalse($s->loadBit());
        self::assertSame('20000000', $s->loadCoins());
        self::assertFalse($s->loadBit());
        self::assertSame(0, $s->remainingBits());
    }

    public function testLoadStringRefTailRoundTripsPayloadFromStoreStringRefTail(): void
    {
        $payload = 'hello-tact-string-✓';
        $cell    = (new Builder())->storeStringRefTail($payload)->endCell();

        $slice = $cell->beginParse();
        self::assertSame($payload, $slice->loadStringRefTail());
    }
}
