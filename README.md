# ton-cell-php

TLB primitives for The Open Network (TON) in pure PHP: `Cell`, `Builder`, `Slice`, and a canonical `BOC` encoder. A direct port of the `@ton/core` cell layer.

The BOC encoder auto-promotes `offset_byte_size` from 1 to 2 once total cell-data size exceeds 255 bytes, matching the reference TypeScript implementation byte-for-byte.

## Status

Pre-1.0. Public API may change before the 1.0 tag.

## Requirements

- PHP 8.3+
- `ext-gmp`

No composer dependencies.

## Credits

PHP port of [`@ton/core`](https://github.com/ton-org/ton-core). TL-B language reference: <https://docs.ton.org/develop/data-formats/tl-b-language>.

## License

MIT License.
