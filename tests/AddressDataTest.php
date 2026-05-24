<?php

declare(strict_types=1);

namespace Amashukov\TonCell\Tests;

use Amashukov\TonCell\AddressData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddressData::class)]
final class AddressDataTest extends TestCase
{
    public function testStoresWcAndHashPartVerbatim(): void
    {
        $hash = str_repeat("\xab", 32);
        $addr = new AddressData(0, $hash);

        self::assertSame(0, $addr->wc);
        self::assertSame($hash, $addr->hashPart);
    }

    public function testAcceptsMasterchainNegativeWc(): void
    {
        $addr = new AddressData(-1, str_repeat("\x00", 32));
        self::assertSame(-1, $addr->wc);
    }

    public function testRejectsWcAboveInt8Range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wc must fit in int8');

        new AddressData(128, str_repeat("\x00", 32));
    }

    public function testRejectsWcBelowInt8Range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wc must fit in int8');

        new AddressData(-129, str_repeat("\x00", 32));
    }

    public function testRejectsShortHashPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hashPart must be exactly 32 bytes');

        new AddressData(0, str_repeat("\x00", 31));
    }

    public function testRejectsLongHashPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hashPart must be exactly 32 bytes');

        new AddressData(0, str_repeat("\x00", 33));
    }
}
