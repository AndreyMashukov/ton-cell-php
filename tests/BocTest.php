<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Boc;
use Amashukov\TonCell\Builder;
use Amashukov\TonCell\Cell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Boc::class)]
final class BocTest extends TestCase
{
    public function testEncodeEmptyCellMatchesReferenceImplementation(): void
    {
        $cell = (new Builder())->endCell();

        self::assertSame(
            'te6cckEBAQEAAgAAAEysuc0=',
            Boc::encodeBase64($cell),
        );
    }

    public function testEncodeUint8MatchesReferenceImplementation(): void
    {
        $cell = (new Builder())->storeUint(0xA5, 8)->endCell();

        self::assertSame(
            'te6cckEBAQEAAwAAAqVpxmbW',
            Boc::encodeBase64($cell),
        );
    }

    public function testEncodeJettonTransferShapeMatchesReferenceImplementation(): void
    {
        $hashPart = hex2bin('83dfd552e63729b472fcbcc8c45ebcc6691702558b68ec7527e1ba403a0f31a8');
        if (false === $hashPart) {
            self::fail('test fixture hex must decode');
        }
        $wallet = new AddressData(0, $hashPart);
        $cell   = (new Builder())
            ->storeUint(0xF8A7EA5, 32)
            ->storeUint(0xC0FFEE, 64)
            ->storeCoins('1000000')
            ->storeAddress($wallet)
            ->endCell();

        self::assertSame(
            'te6cckEBAQEAMwAAYQ+KfqUAAAAAAMD/7jD0JAgBB7+qpcxuU2jl+XmRiL15jNIuBKsW0djqT8N0gHQeY1Edgj8I',
            Boc::encodeBase64($cell),
        );
    }

    public function testEncodeTextCommentMatchesReferenceImplementation(): void
    {
        $cell = (new Builder())
            ->storeUint(0, 32)
            ->storeStringTail('memo1')
            ->endCell();

        self::assertSame(
            'te6cckEBAQEACwAAEgAAAABtZW1vMZWDxc0=',
            Boc::encodeBase64($cell),
        );
    }

    public function testEncodeWithChildRefMatchesReferenceImplementation(): void
    {
        $child  = (new Builder())->storeUint(0xDEADBEEF, 32)->endCell();
        $parent = (new Builder())->storeUint(1, 8)->storeRef($child)->endCell();

        self::assertSame(
            'te6cckEBAgEACgABAgEBAAjerb7vWdolGQ==',
            Boc::encodeBase64($parent),
        );
    }

    public function testEncodedBocHasMagicPrefix(): void
    {
        $cell    = (new Builder())->endCell();
        $encoded = Boc::encode($cell);

        self::assertStringStartsWith(Boc::MAGIC, $encoded);
    }

    public function testEncodedBocEndsWithFourByteCrc(): void
    {
        $cell    = (new Builder())->storeUint(0xFF, 8)->endCell();
        $encoded = Boc::encode($cell);
        $crc     = substr($encoded, -4);

        $expected = strrev(hash('crc32c', substr($encoded, 0, -4), true));
        self::assertSame($expected, $crc);
    }

    public function testEncodeUsesTwoByteOffsetWhenTotalCellSizeExceeds255(): void
    {
        $bigData = str_repeat('A', 127);
        $leaf    = (new Builder())->storeBuffer($bigData)->endCell();
        $cell    = (new Builder())->storeBuffer($bigData)->storeRef($leaf)->endCell();

        $encoded = Boc::encode($cell);

        self::assertSame(2, \ord($encoded[5]), 'offset_byte_size must auto-promote to 2 when totalCellsSize > 255');
    }

    public function testEncodeRejectsCellCountAboveByteRange(): void
    {
        $current = (new Builder())->endCell();
        for ($i = 0; $i < 256; ++$i) {
            $current = (new Builder())->storeRef($current)->endCell();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds ref_byte_size=1 limit');

        Boc::encode($current);
    }

    public function testEncodeDeduplicatesSharedRefViaDfsRevisit(): void
    {
        $shared = (new Builder())->storeUint(0xABCD, 16)->endCell();

        $parentA = (new Builder())->storeUint(0x01, 8)->storeRef($shared)->endCell();
        $parentB = (new Builder())->storeUint(0x02, 8)->storeRef($shared)->endCell();

        $root = (new Builder())
            ->storeRef($parentA)
            ->storeRef($parentB)
            ->endCell()
        ;

        $bin = Boc::encode($root);

        self::assertSame(4, ord($bin[6]), 'shared cell must be emitted once, not twice');
    }

    private function assertRoundTrip(Cell $cell): Cell
    {
        $encoded = Boc::encode($cell);
        $decoded = Boc::decode($encoded);

        self::assertSame($cell->hash(), $decoded->hash(), 'decode(encode($c)) must be structurally identical to $c');
        self::assertSame($encoded, Boc::encode($decoded), 'encode(decode(encode($c))) must be byte-stable');
        self::assertSame($cell->hash(), Boc::decodeBase64(Boc::encodeBase64($cell))->hash(), 'base64 round-trip must hold too');

        return $decoded;
    }

    public function testDecodeRoundTripEmptyCell(): void
    {
        $this->assertRoundTrip((new Builder())->endCell());
    }

    public function testDecodeRoundTripUint8(): void
    {
        $decoded = $this->assertRoundTrip((new Builder())->storeUint(0xA5, 8)->endCell());

        self::assertSame(0xA5, $decoded->beginParse()->loadUint(8));
    }

    public function testDecodeRoundTripTextComment(): void
    {
        $decoded = $this->assertRoundTrip((new Builder())->storeUint(0, 32)->storeStringTail('memo1')->endCell());

        $slice = $decoded->beginParse();
        self::assertSame(0, $slice->loadUint(32));
        self::assertSame('memo1', $slice->loadStringTail());
    }

    public function testDecodeRoundTripWithChildRef(): void
    {
        $child   = (new Builder())->storeUint(0xDEADBEEF, 32)->endCell();
        $parent  = (new Builder())->storeUint(1, 8)->storeRef($child)->endCell();
        $decoded = $this->assertRoundTrip($parent);

        $slice = $decoded->beginParse();
        self::assertSame(1, $slice->loadUint(8));
        self::assertSame(0xDEADBEEF, $slice->loadRef()->beginParse()->loadUint(32));
    }

    public function testDecodeRoundTripTep74JettonBodyFieldByField(): void
    {
        $vaultHash = hex2bin('e4d954ef9f4e1250a26b5bbad76a1cdd17cfd08babadbf4c23e372270aef6f76');
        if (false === $vaultHash) {
            self::fail('vault hash fixture must decode');
        }
        $vault = new AddressData(0, $vaultHash);
        $memo  = '00000000-0000-0000-0000-000000000001';

        $forward = (new Builder())->storeUint(0, 32)->storeStringTail($memo)->endCell();
        $body    = (new Builder())
            ->storeUint(0x0F8A7EA5, 32)
            ->storeUint(0, 64)
            ->storeCoins('10000000')
            ->storeAddress($vault)
            ->storeAddress($vault)
            ->storeBit(0)
            ->storeCoins(50_000_000)
            ->storeBit(1)
            ->storeRef($forward)
            ->endCell();

        $decoded = $this->assertRoundTrip($body);
        $slice   = $decoded->beginParse();

        self::assertSame(0x0F8A7EA5, $slice->loadUint(32));
        self::assertSame(0, $slice->loadUint(64));
        self::assertSame('10000000', $slice->loadCoins());

        $dest = $slice->loadAddress();
        self::assertInstanceOf(AddressData::class, $dest);
        self::assertSame(0, $dest->wc);
        self::assertSame($vaultHash, $dest->hashPart);

        $respDest = $slice->loadAddress();
        self::assertInstanceOf(AddressData::class, $respDest);
        self::assertSame($vaultHash, $respDest->hashPart);

        self::assertFalse($slice->loadBit());
        self::assertSame('50000000', $slice->loadCoins());
        self::assertTrue($slice->loadBit());

        $forwardSlice = $slice->loadRef()->beginParse();
        self::assertSame(0, $forwardSlice->loadUint(32));
        self::assertSame($memo, $forwardSlice->loadStringTail());
    }

    public function testDecodeRoundTripMaxBits(): void
    {
        $builder = new Builder();
        for ($i = 0; $i < 1023; ++$i) {
            $builder->storeBit(($i % 3) === 0 ? 1 : 0);
        }
        $decoded = $this->assertRoundTrip($builder->endCell());

        $slice = $decoded->beginParse();
        for ($i = 0; $i < 1023; ++$i) {
            self::assertSame(($i % 3) === 0, $slice->loadBit(), sprintf('bit %d must survive round-trip', $i));
        }
    }

    public function testDecodeRoundTripDeepNestedRefs(): void
    {
        $cell = (new Builder())->storeUint(0xFF, 8)->endCell();
        for ($depth = 0; $depth < 4; ++$depth) {
            $cell = (new Builder())->storeUint($depth, 8)->storeRef($cell)->endCell();
        }
        $decoded = $this->assertRoundTrip($cell);

        $slice = $decoded->beginParse();
        for ($depth = 3; $depth >= 0; --$depth) {
            self::assertSame($depth, $slice->loadUint(8));
            $slice = $slice->loadRef()->beginParse();
        }
        self::assertSame(0xFF, $slice->loadUint(8));
    }

    public function testDecodeRoundTripTwoByteOffsetTree(): void
    {
        $bigData = str_repeat('A', 127);
        $leaf    = (new Builder())->storeBuffer($bigData)->endCell();
        $root    = (new Builder())->storeBuffer($bigData)->storeRef($leaf)->endCell();

        $encoded = Boc::encode($root);
        self::assertSame(2, \ord($encoded[5]), 'fixture must exercise offset_byte_size=2');

        $this->assertRoundTrip($root);
    }

    public function testDecodeRoundTripSharedRefDag(): void
    {
        $shared  = (new Builder())->storeUint(0xABCD, 16)->endCell();
        $parentA = (new Builder())->storeUint(0x01, 8)->storeRef($shared)->endCell();
        $parentB = (new Builder())->storeUint(0x02, 8)->storeRef($shared)->endCell();
        $root    = (new Builder())->storeRef($parentA)->storeRef($parentB)->endCell();

        $decoded = $this->assertRoundTrip($root);

        $slice = $decoded->beginParse();
        self::assertSame(0xABCD, $slice->loadRef()->beginParse()->loadRef()->beginParse()->loadUint(16));
        self::assertSame(0xABCD, $slice->loadRef()->beginParse()->loadRef()->beginParse()->loadUint(16));
    }

    public function testDecodeRejectsBadMagic(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bad magic');

        Boc::decode(str_repeat("\x00", 16));
    }

    public function testDecodeRejectsTruncatedBuffer(): void
    {
        $encoded = Boc::encode((new Builder())->storeUint(0xAB, 8)->endCell());

        $this->expectException(RuntimeException::class);

        Boc::decode(substr($encoded, 0, \strlen($encoded) - 6));
    }

    public function testDecodeRejectsCorruptedCrc(): void
    {
        $encoded                       = Boc::encode((new Builder())->storeUint(0xAB, 8)->endCell());
        $encoded[\strlen($encoded) - 1] = \chr(\ord($encoded[\strlen($encoded) - 1]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRC32C mismatch');

        Boc::decode($encoded);
    }

    public function testDecodeBase64RejectsNonBase64(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid base64');

        Boc::decodeBase64('!!! not base64 !!!');
    }
}
