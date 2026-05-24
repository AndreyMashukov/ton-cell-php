# amashukov/ton-cell-php

Pure-PHP TLB Cell, Builder, Slice and canonical BOC encoder for The Open Network (TON) — byte-for-byte parity with `@ton/core`.

[![CI](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/ton-cell-php/ci.yml?branch=main&label=CI)](https://github.com/AndreyMashukov/ton-cell-php/actions)
[![PHPStan L9](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/ton-cell-php/stan.yml?branch=main&label=PHPStan%20L9)](https://github.com/AndreyMashukov/ton-cell-php/actions)
[![Latest Version](https://img.shields.io/packagist/v/amashukov/ton-cell-php)](https://packagist.org/packages/amashukov/ton-cell-php)
[![Downloads](https://img.shields.io/packagist/dt/amashukov/ton-cell-php)](https://packagist.org/packages/amashukov/ton-cell-php)
[![PHP](https://img.shields.io/packagist/dependency-v/amashukov/ton-cell-php/php)](https://packagist.org/packages/amashukov/ton-cell-php)
[![License](https://img.shields.io/packagist/l/amashukov/ton-cell-php)](LICENSE)
[![Stars](https://img.shields.io/github/stars/AndreyMashukov/ton-cell-php?style=social)](https://github.com/AndreyMashukov/ton-cell-php)

`amashukov/ton-cell-php` implements the TLB (Type Language - Binary) cell layer for The Open Network (TON) in pure PHP: `Cell`, `Builder`, `Slice`, and a canonical `BOC` (Bag of Cells) serializer. It is a byte-exact equivalent of the `@ton/core` cell layer used across TON SDKs, suitable for building and parsing jetton transfers, contract messages and any TLB-encoded payload from PHP.

## Features

- **`Builder`** — fluent cell construction: bits, unsigned/signed ints, coins, addresses, refs, string tails.
- **`Slice`** — TLB parser mirroring the builder surface.
- **Canonical `BOC` serializer** — emits `@ton/core` v15's canonical wire format (`has_crc32c=1`, little-endian CRC32C tail) with `offset_byte_size` auto-promotion from 1 to 2 when cell data exceeds 255 bytes.
- **Byte-for-byte parity** with `@ton/core` v15 — validated against upstream fixtures.
- **Big integers via `ext-gmp`** — values crossing `PHP_INT_MAX` flow in and out as decimal strings.
- **Cell hashing** — 32-byte SHA-256 of the recursive TLB representation for Ed25519 signing and address derivation.
- PHPStan level 9 clean, `@PER-CS` formatted, CI-tested.

## Why amashukov/ton-cell-php

TON SDKs are written in TypeScript (`@ton/core`) and a PHP project either shells out to Node or reimplements the cell layer ad hoc. `amashukov/ton-cell-php` gives you the cell / BOC primitives natively in PHP with byte-for-byte parity against `@ton/core` v15, so a BOC built in PHP is wire-identical to one built by the canonical SDK — no Node sidecar, no FFI.

## Installation

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

## Related packages

Part of a modular pure-PHP blockchain toolkit:

| Package | Purpose |
|---|---|
| [amashukov/ton-cell-php](https://github.com/AndreyMashukov/ton-cell-php) | TON TLB Cell / Builder / Slice / BOC |
| [amashukov/ton-crypto-php](https://github.com/AndreyMashukov/ton-crypto-php) | TON Ed25519 / mnemonic crypto |
| [amashukov/ton-wallet-php](https://github.com/AndreyMashukov/ton-wallet-php) | TON wallet contract tooling |
| [amashukov/toncenter-client-php](https://github.com/AndreyMashukov/toncenter-client-php) | toncenter JSON-RPC client |
| [amashukov/ton-php](https://github.com/AndreyMashukov/ton-php) | TON umbrella package |
| [amashukov/keccak-php](https://github.com/AndreyMashukov/keccak-php) | Keccak-256 / SHA-3 / SHAKE hashing |
| [amashukov/secp256k1-php](https://github.com/AndreyMashukov/secp256k1-php) | secp256k1 ECDSA sign / verify / recover |
| [amashukov/rlp-php](https://github.com/AndreyMashukov/rlp-php) | Ethereum RLP encode / decode |

## Quality

- PHPStan level 9.
- php-cs-fixer with the `@PER-CS` ruleset.
- GitHub Actions CI on every push.
- Byte-for-byte parity tests against `@ton/core` v15 fixtures.

## Reference

- TL-B language: <https://docs.ton.org/develop/data-formats/tl-b-language>
- Cell representation + BOC format: <https://docs.ton.org/develop/data-formats/cell-boc>

## License

MIT License.
