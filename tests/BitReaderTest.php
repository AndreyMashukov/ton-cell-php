<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\BitReader;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BitReader::class)]
final class BitReaderTest extends TestCase
{
    public function testReadUintByteAligned(): void
    {
        $reader = new BitReader("\xAB\xCD", 16);

        self::assertSame(0xAB, $reader->readUint(8));
        self::assertSame(0xCD, $reader->readUint(8));
    }

    public function testReadUintCrossesByteBoundary(): void
    {
        $reader = new BitReader("\xAB\xCD", 16);

        self::assertSame(0xA, $reader->readUint(4));
        self::assertSame(0xBCD, $reader->readUint(12));
    }

    public function testReadIntPositive(): void
    {
        $reader = new BitReader("\x7F", 8);

        self::assertSame(127, $reader->readInt(8));
    }

    public function testReadIntNegativeTwosComplement(): void
    {
        $reader = new BitReader("\xFF", 8);

        self::assertSame(-1, $reader->readInt(8));
    }

    public function testReadIntMinBoundary(): void
    {
        $reader = new BitReader("\x80", 8);

        self::assertSame(-128, $reader->readInt(8));
    }

    public function testNegativeBitsRejected(): void
    {
        $reader = new BitReader("\x00", 8);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bits=-1 out of [0,32]');
        $reader->readUint(-1);
    }

    public function testTooManyBitsRejected(): void
    {
        $reader = new BitReader("\x00\x00\x00\x00\x00", 40);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bits=33 out of [0,32]');
        $reader->readUint(33);
    }

    public function testOverrunRejected(): void
    {
        $reader = new BitReader("\xFF", 8);
        $reader->readUint(6);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('out-of-bounds read at 6+4 > 8');
        $reader->readUint(4);
    }
}
