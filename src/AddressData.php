<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use InvalidArgumentException;

final readonly class AddressData
{
    public function __construct(
        public int $wc,
        public string $hashPart,
    ) {
        if ($wc < -128 || $wc > 127) {
            throw new InvalidArgumentException(sprintf('AddressData: wc must fit in int8, got %d', $wc));
        }
        if (32 !== \strlen($hashPart)) {
            throw new InvalidArgumentException(sprintf('AddressData: hashPart must be exactly 32 bytes, got %d', \strlen($hashPart)));
        }
    }
}
