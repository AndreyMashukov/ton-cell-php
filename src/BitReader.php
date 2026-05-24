<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use DomainException;

final class BitReader
{
    private int $bitPos = 0;

    public function __construct(
        private readonly string $data,
        private readonly int $bitLen,
    ) {}

    public function readUint(int $bits): int
    {
        if ($bits < 0 || $bits > 32) {
            throw new DomainException(sprintf('BitReader: bits=%d out of [0,32]', $bits));
        }
        if ($this->bitPos + $bits > $this->bitLen) {
            throw new DomainException(sprintf('BitReader: out-of-bounds read at %d+%d > %d', $this->bitPos, $bits, $this->bitLen));
        }
        $value = 0;
        for ($i = 0; $i < $bits; ++$i) {
            $bytePos = (int) (($this->bitPos + $i) / 8);
            $bitOff  = 7 - (($this->bitPos + $i) % 8);
            $bit     = (\ord($this->data[$bytePos]) >> $bitOff) & 1;
            $value   = ($value << 1) | $bit;
        }
        $this->bitPos += $bits;

        return $value;
    }

    public function readInt(int $bits): int
    {
        $u       = $this->readUint($bits);
        $signBit = 1 << ($bits - 1);
        if (0 !== ($u & $signBit)) {
            $u -= 1 << $bits;
        }

        return $u;
    }
}
