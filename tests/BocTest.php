<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Boc;
use Amashukov\TonCell\Builder;
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
}
