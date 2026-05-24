# ton-cell-php

TLB primitives for The Open Network (TON) in pure PHP: `Cell`, `Builder`, `Slice`, and a canonical `BOC` encoder. Byte-exact equivalent of the `@ton/core` cell layer used across TON SDKs.

## Install

```bash
composer require amashukov/ton-cell-php
```

## Usage

### Build a cell

```php
use Amashukov\TonCell\AddressData;
use Amashukov\TonCell\Builder;

$cell = (new Builder())
    ->storeUint(0xF8A7EA5, 32)                       // jetton-transfer op
    ->storeUint(0xC0FFEE, 64)                        // query id
    ->storeCoins('1000000')                          // amount (decimal string for bigints)
    ->storeAddress(new AddressData(0, $hashPart32))  // destination
    ->endCell();
```

`Builder` exposes `storeBit`, `storeUint(int|string $value, int $bits)`, `storeInt`, `storeCoins`, `storeBits`, `storeBuffer`, `storeAddress`, `storeRef`, `storeStringTail`, `storeStringRefTail`, `storeBuilder`, `storeCellInline`. Bigint values cross `PHP_INT_MAX` safely via `ext-gmp` (decimal-string in, decimal-string out).

### Parse a cell

```php
$slice = $cell->beginParse();

$op       = $slice->loadUint(32);                  // int when fitting, string for >PHP_INT_MAX
$queryId  = $slice->loadUint(64);
$amount   = $slice->loadCoins();                   // always decimal string
$dest     = $slice->loadAddress();                 // AddressData|null
```

`Slice` exposes `loadBit`, `loadUint`, `loadUintString`, `loadCoins`, `loadAddress`, `loadBits`, `loadRef`, `loadStringTail`, `loadStringRefTail`, `remainingBits`, `remainingRefs`. Anycast and non-`addr_std$10` shapes are rejected with `RuntimeException`.

### Compute the cell hash

```php
$hash = $cell->hash();   // 32-byte SHA-256 of the recursive TLB representation
$depth = $cell->maxDepth();
```

Used for Ed25519 message signing and TON address derivation. Memoised across calls.

### Serialise to BOC

```php
use Amashukov\TonCell\Boc;

$wireBytes  = Boc::encode($cell);          // canonical TON BOC bytes with CRC32C tail
$base64     = Boc::encodeBase64($cell);    // ready for toncenter /sendBoc
```

The BOC encoder emits `@ton/core` v15's canonical format (`has_crc32c=1`, little-endian CRC32C tail) and auto-promotes `offset_byte_size` from 1 to 2 when total cell-data size exceeds 255 bytes. Hard cap is 65 535 bytes per BOC; extend the encoder, not the rule.

### `AddressData` value object

`AddressData` is a minimal `wc + hashPart` carrier — just enough for the cell layer to serialise the `addr_std$10` TLB shape. Full address parsing (user-friendly `EQ…` / `UQ…` base64 forms, CRC-16 checksum, testnet flag) belongs to a downstream wallet package and lives on top of this VO.

### `BitReader`

Bit-level reader over a binary string with a known precise bit length — useful for parsing TLB fields that aren't byte-aligned (for example, contract-emitted event payloads). `readUint(bits)` and `readInt(bits)` cover `[0, 32]` with strict overrun + range guards.

## Requirements

- PHP 8.3+
- `ext-gmp`

No composer dependencies.

## Reference

- TL-B language: <https://docs.ton.org/develop/data-formats/tl-b-language>
- Cell representation + BOC format: <https://docs.ton.org/develop/data-formats/cell-boc>

## License

MIT License.
