<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use GMP;
use InvalidArgumentException;

final class Builder
{
    private string $bitsPacked = '';

    private int $bitCount = 0;

    private readonly CellRefList $refs;

    public function __construct()
    {
        $this->refs = new CellRefList();
    }

    public function storeBit(bool|int $value): self
    {
        $this->ensureCapacity(1);
        $this->appendBit($value ? 1 : 0);

        return $this;
    }

    public function storeUint(int|string $value, int $bits): self
    {
        if ($bits < 1 || $bits > 256) {
            throw new InvalidArgumentException(sprintf('storeUint bits must be 1..256, got %d', $bits));
        }
        $this->ensureCapacity($bits);

        $gmp = $this->toGmp($value);
        if (gmp_sign($gmp) < 0) {
            throw new InvalidArgumentException('storeUint value must be non-negative');
        }
        if (gmp_cmp($gmp, gmp_pow(2, $bits)) >= 0) {
            throw new InvalidArgumentException(sprintf('storeUint value 0x%s does not fit in %d bits', gmp_strval($gmp, 16), $bits));
        }

        for ($i = $bits - 1; $i >= 0; --$i) {
            $this->appendBit(gmp_testbit($gmp, $i) ? 1 : 0);
        }

        return $this;
    }

    public function storeInt(int|string $value, int $bits): self
    {
        if ($bits < 1 || $bits > 257) {
            throw new InvalidArgumentException(sprintf('storeInt bits must be 1..257, got %d', $bits));
        }
        $this->ensureCapacity($bits);

        $gmp     = $this->toGmp($value);
        $modulus = gmp_pow(2, $bits);
        $half    = gmp_pow(2, $bits - 1);
        if (gmp_cmp($gmp, $half) >= 0 || gmp_cmp($gmp, gmp_neg($half)) < 0) {
            throw new InvalidArgumentException(sprintf('storeInt value 0x%s does not fit in %d signed bits', gmp_strval($gmp, 16), $bits));
        }
        if (gmp_sign($gmp) < 0) {
            $gmp = gmp_add($gmp, $modulus);
        }

        for ($i = $bits - 1; $i >= 0; --$i) {
            $this->appendBit(gmp_testbit($gmp, $i) ? 1 : 0);
        }

        return $this;
    }

    public function storeCoins(int|string $value): self
    {
        $gmp = $this->toGmp($value);
        if (gmp_sign($gmp) < 0) {
            throw new InvalidArgumentException('storeCoins value must be non-negative');
        }

        if (0 === gmp_sign($gmp)) {
            return $this->storeUint(0, 4);
        }

        $hex = gmp_strval($gmp, 16);
        if (1 === \strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        $byteLen = \intdiv(\strlen($hex), 2);
        if ($byteLen > 15) {
            throw new InvalidArgumentException(sprintf('storeCoins value too large: %d bytes (max 15)', $byteLen));
        }

        $this->storeUint($byteLen, 4);
        $this->storeUint($value, $byteLen * 8);

        return $this;
    }

    public function storeAddress(?AddressData $address): self
    {
        if (!$address instanceof AddressData) {
            return $this->storeUint(0, 2);
        }

        $this->storeUint(2, 2);
        $this->storeBit(false);
        $wc = $address->wc & 0xFF;
        $this->storeUint($wc, 8);
        $this->storeBits($address->hashPart, 256);

        return $this;
    }

    public function storeBits(string $bytes, int $bits): self
    {
        $expected = (int) ceil($bits / 8);
        if (\strlen($bytes) !== $expected) {
            throw new InvalidArgumentException(sprintf('storeBits expects %d bytes for %d bits, got %d', $expected, $bits, \strlen($bytes)));
        }
        $this->ensureCapacity($bits);

        for ($i = 0; $i < $bits; ++$i) {
            $byteIdx = \intdiv($i, 8);
            $bitIdx  = 7 - ($i % 8);
            $this->appendBit((\ord($bytes[$byteIdx]) >> $bitIdx) & 1);
        }

        return $this;
    }

    public function storeBuffer(string $bytes): self
    {
        return $this->storeBits($bytes, \strlen($bytes) * 8);
    }

    public function storeBuilder(self $other): self
    {
        $this->ensureCapacity($other->bitCount);
        if ($this->refs->count() + $other->refs->count() > Cell::MAX_REFS) {
            throw new InvalidArgumentException(sprintf('storeBuilder: combined refs %d + %d > max %d', $this->refs->count(), $other->refs->count(), Cell::MAX_REFS));
        }
        for ($i = 0; $i < $other->bitCount; ++$i) {
            $this->appendBit($other->readBit($i));
        }
        foreach ($other->refs as $ref) {
            $this->refs->add($ref);
        }

        return $this;
    }

    public function storeCellInline(Cell $cell): self
    {
        $this->ensureCapacity($cell->bitLength);
        if ($this->refs->count() + $cell->refs->count() > Cell::MAX_REFS) {
            throw new InvalidArgumentException(sprintf('storeCellInline: combined refs %d + %d > max %d', $this->refs->count(), $cell->refs->count(), Cell::MAX_REFS));
        }
        for ($i = 0; $i < $cell->bitLength; ++$i) {
            $byteIdx = \intdiv($i, 8);
            $bitIdx  = 7 - ($i % 8);
            $this->appendBit((\ord($cell->bits[$byteIdx]) >> $bitIdx) & 1);
        }
        foreach ($cell->refs as $ref) {
            $this->refs->add($ref);
        }

        return $this;
    }

    public function storeRef(Cell $cell): self
    {
        if ($this->refs->count() >= Cell::MAX_REFS) {
            throw new InvalidArgumentException(sprintf('Cell already has the maximum of %d refs', Cell::MAX_REFS));
        }
        $this->refs->add($cell);

        return $this;
    }

    public function storeStringTail(string $str): self
    {
        $bytes = $str;
        $head  = min(\strlen($bytes), \intdiv(Cell::MAX_BITS - $this->bitCount, 8));
        for ($i = 0; $i < $head; ++$i) {
            $this->storeUint(\ord($bytes[$i]), 8);
        }
        if ($head === \strlen($bytes)) {
            return $this;
        }

        $tailBuilder = new self();
        $tailBuilder->storeStringTail(substr($bytes, $head));
        $this->storeRef($tailBuilder->endCell());

        return $this;
    }

    public function storeStringRefTail(string $str): self
    {
        $child = (new self())->storeStringTail($str);

        return $this->storeRef($child->endCell());
    }

    public function endCell(): Cell
    {
        return new Cell($this->bitsPacked, $this->bitCount, $this->refs);
    }

    public function bits(): int
    {
        return $this->bitCount;
    }

    public function refs(): int
    {
        return $this->refs->count();
    }

    private function appendBit(int $bit): void
    {
        $byteIdx = \intdiv($this->bitCount, 8);
        $bitIdx  = 7 - ($this->bitCount % 8);
        if ($byteIdx >= \strlen($this->bitsPacked)) {
            $this->bitsPacked .= "\x00";
        }
        if (1 === ($bit & 1)) {
            $this->bitsPacked[$byteIdx] = \chr((\ord($this->bitsPacked[$byteIdx]) | (1 << $bitIdx)) & 0xFF);
        }
        ++$this->bitCount;
    }

    private function readBit(int $idx): int
    {
        $byteIdx = \intdiv($idx, 8);
        $bitIdx  = 7 - ($idx % 8);

        return (\ord($this->bitsPacked[$byteIdx]) >> $bitIdx) & 1;
    }

    private function ensureCapacity(int $bits): void
    {
        if ($this->bitCount + $bits > Cell::MAX_BITS) {
            throw new InvalidArgumentException(sprintf('Builder overflow: %d + %d bits > Cell::MAX_BITS (%d)', $this->bitCount, $bits, Cell::MAX_BITS));
        }
    }

    private function toGmp(int|string $value): GMP
    {
        if (\is_int($value)) {
            return gmp_init($value);
        }

        if (str_starts_with($value, '0x') || str_starts_with($value, '0X')) {
            return gmp_init(substr($value, 2), 16);
        }

        return gmp_init($value, 10);
    }
}
