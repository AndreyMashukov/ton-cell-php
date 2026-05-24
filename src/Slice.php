<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use GMP;
use InvalidArgumentException;
use RuntimeException;

final class Slice
{
    private int $bitOffset = 0;

    private int $refOffset = 0;

    /**
     * @param list<Cell> $refs
     */
    public function __construct(
        private readonly string $bits,
        private readonly int $bitLength,
        private readonly array $refs,
    ) {}

    public function loadBit(): bool
    {
        $this->ensureBits(1);
        $byteIdx = \intdiv($this->bitOffset, 8);
        $bitIdx  = 7 - ($this->bitOffset % 8);
        ++$this->bitOffset;

        return 0 !== ((\ord($this->bits[$byteIdx]) >> $bitIdx) & 1);
    }

    public function loadUint(int $bits): int|string
    {
        if ($bits < 1 || $bits > 256) {
            throw new InvalidArgumentException(sprintf('loadUint bits must be 1..256, got %d', $bits));
        }
        $this->ensureBits($bits);

        $gmp = gmp_init(0);
        for ($i = 0; $i < $bits; ++$i) {
            $bit = $this->loadBit() ? 1 : 0;
            $gmp = gmp_or(gmp_mul($gmp, 2), $bit);
        }

        return $this->gmpToScalar($gmp);
    }

    public function loadUintString(int $bits): string
    {
        $v = $this->loadUint($bits);

        return \is_int($v) ? (string) $v : $v;
    }

    public function loadCoins(): string
    {
        $byteLen = $this->loadUint(4);
        if (!\is_int($byteLen)) {
            throw new RuntimeException('loadCoins: VarUInteger16 length prefix is not a small int');
        }
        if (0 === $byteLen) {
            return '0';
        }

        return $this->loadUintString($byteLen * 8);
    }

    public function loadAddress(): ?AddressData
    {
        $tag = $this->loadUint(2);
        if (0 === $tag) {
            return null;
        }
        if (2 !== $tag) {
            throw new RuntimeException(sprintf('Unsupported address tag: 0b%s (only addr_std$10 supported)', decbin((int) $tag)));
        }

        $hasAnycast = $this->loadBit();
        if ($hasAnycast) {
            throw new RuntimeException('Anycast addresses are not supported');
        }

        $wcRaw = $this->loadUint(8);
        if (!\is_int($wcRaw)) {
            throw new RuntimeException('loadAddress: 8-bit wc must fit in int');
        }
        $wc       = $wcRaw >= 0x80 ? $wcRaw - 0x100 : $wcRaw;
        $hashPart = $this->loadBits(256);

        return new AddressData(wc: $wc, hashPart: $hashPart);
    }

    public function loadBits(int $bits): string
    {
        $this->ensureBits($bits);
        $byteCount = (int) ceil($bits / 8);
        $out       = '';
        $byte      = 0;
        $bitInByte = 0;

        for ($i = 0; $i < $bits; ++$i) {
            if ($this->loadBit()) {
                $byte |= 1 << (7 - $bitInByte);
            }
            ++$bitInByte;
            if (8 === $bitInByte) {
                $out .= \chr($byte & 0xFF);
                $byte      = 0;
                $bitInByte = 0;
            }
        }
        if ($bitInByte > 0) {
            $out .= \chr($byte & 0xFF);
        }

        if (\strlen($out) !== $byteCount) {
            throw new RuntimeException(sprintf('loadBits internal byte-count mismatch: got %d, expected %d', \strlen($out), $byteCount));
        }

        return $out;
    }

    public function loadRef(): Cell
    {
        if ($this->refOffset >= \count($this->refs)) {
            throw new RuntimeException('Slice has no more refs');
        }

        return $this->refs[$this->refOffset++];
    }

    public function loadStringTail(): string
    {
        $out  = '';
        $tail = $this->remainingBits() % 8;
        if (0 !== $tail) {
            throw new RuntimeException(sprintf('loadStringTail: %d remaining bits not byte-aligned', $this->remainingBits()));
        }
        while ($this->remainingBits() >= 8) {
            $byte = $this->loadUint(8);
            if (!\is_int($byte)) {
                throw new RuntimeException('loadStringTail: 8-bit byte must fit in int');
            }
            $out .= \chr($byte & 0xFF);
        }
        if ($this->refOffset < \count($this->refs)) {
            $next = $this->loadRef()->beginParse();
            $out .= $next->loadStringTail();
        }

        return $out;
    }

    public function loadStringRefTail(): string
    {
        return $this->loadRef()->beginParse()->loadStringTail();
    }

    public function remainingBits(): int
    {
        return $this->bitLength - $this->bitOffset;
    }

    public function remainingRefs(): int
    {
        return \count($this->refs) - $this->refOffset;
    }

    private function ensureBits(int $bits): void
    {
        if ($this->remainingBits() < $bits) {
            throw new RuntimeException(sprintf('Slice underflow: requested %d bits, only %d remaining', $bits, $this->remainingBits()));
        }
    }

    private function gmpToScalar(GMP $gmp): int|string
    {
        $str = gmp_strval($gmp);
        if (gmp_cmp($gmp, gmp_init((string) \PHP_INT_MAX)) <= 0) {
            return (int) $str;
        }

        return $str;
    }
}
