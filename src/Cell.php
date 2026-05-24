<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use InvalidArgumentException;

final class Cell
{
    public const int MAX_BITS = 1023;

    public const int MAX_REFS = 4;

    public readonly CellRefList $refs;

    private ?string $cachedHash = null;

    private ?int $cachedDepth = null;

    public function __construct(
        public readonly string $bits,
        public readonly int $bitLength,
        ?CellRefList $refs = null,
    ) {
        $this->refs = $refs ?? new CellRefList();
        if ($bitLength < 0 || $bitLength > self::MAX_BITS) {
            throw new InvalidArgumentException(sprintf('Cell bitLength must be 0..%d, got %d', self::MAX_BITS, $bitLength));
        }

        $expectedBytes = (int) ceil($bitLength / 8);
        if (\strlen($bits) !== $expectedBytes) {
            throw new InvalidArgumentException(sprintf('Cell bits length mismatch: bitLength=%d expects %d bytes, got %d', $bitLength, $expectedBytes, \strlen($bits)));
        }

        if ($this->refs->count() > self::MAX_REFS) {
            throw new InvalidArgumentException(sprintf('Cell can hold at most %d refs, got %d', self::MAX_REFS, $this->refs->count()));
        }
    }

    public function beginParse(): Slice
    {
        return new Slice($this->bits, $this->bitLength, $this->refs->toArray());
    }

    public function hash(): string
    {
        return $this->cachedHash ??= hash('sha256', $this->repr(), true);
    }

    public function maxDepth(): int
    {
        if (null !== $this->cachedDepth) {
            return $this->cachedDepth;
        }
        if ($this->refs->isEmpty()) {
            return $this->cachedDepth = 0;
        }
        $childMax = 0;
        foreach ($this->refs as $ref) {
            $d = $ref->maxDepth();
            if ($d > $childMax) {
                $childMax = $d;
            }
        }

        return $this->cachedDepth = 1 + $childMax;
    }

    private function repr(): string
    {
        $refs       = $this->refs->count();
        $byteCount  = (int) ceil($this->bitLength / 8);
        $remainder  = $this->bitLength % 8;
        $d1         = $refs;
        $d2         = $byteCount * 2 - (0 !== $remainder ? 1 : 0);

        $data = $this->bits;
        if (0 !== $remainder) {
            $lastIdx        = \strlen($data) - 1;
            $marker         = 1 << (7 - $remainder);
            $data[$lastIdx] = \chr((\ord($data[$lastIdx]) | $marker) & 0xFF);
        }

        $repr = \chr($d1 & 0xFF) . \chr($d2 & 0xFF) . $data;
        foreach ($this->refs as $ref) {
            $repr .= pack('n', $ref->maxDepth());
        }
        foreach ($this->refs as $ref) {
            $repr .= $ref->hash();
        }

        return $repr;
    }
}
