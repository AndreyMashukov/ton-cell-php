<?php

declare(strict_types=1);

namespace Amashukov\TonCell;

use LogicException;
use RuntimeException;

final readonly class Boc
{
    public const string MAGIC = "\xb5\xee\x9c\x72";

    public static function encode(Cell $root): string
    {
        $ordered = [];
        $indexed = [];
        self::dfs($root, $ordered, $indexed);

        $cellCount = \count($ordered);
        if ($cellCount > 0xFF) {
            throw new RuntimeException(sprintf('BOC encoder: %d cells exceeds ref_byte_size=1 limit (256)', $cellCount));
        }

        $cellPayloads = [];
        foreach ($ordered as $cell) {
            $cellPayloads[] = self::encodeCell($cell, $indexed);
        }
        $totalCellsSize = array_sum(array_map(strlen(...), $cellPayloads));
        if ($totalCellsSize > 0xFFFF) {
            throw new RuntimeException(sprintf('BOC encoder: total cell size %d exceeds offset_byte_size=2 limit (65535)', $totalCellsSize));
        }

        $offsetByteSize = $totalCellsSize > 0xFF ? 2 : 1;

        $flags = 0x40 | 1;

        $head = self::MAGIC
            . \chr($flags)
            . \chr($offsetByteSize)
            . \chr($cellCount)
            . \chr(1)
            . \chr(0)
            . self::packBigEndian($totalCellsSize, $offsetByteSize)
            . \chr(0)
            . implode('', $cellPayloads);

        $crc = strrev(hash('crc32c', $head, true));

        return $head . $crc;
    }

    public static function encodeBase64(Cell $root): string
    {
        return base64_encode(self::encode($root));
    }

    /**
     * @param list<Cell>      $ordered
     * @param array<int, int> $indexed
     */
    private static function dfs(Cell $cell, array &$ordered, array &$indexed): int
    {
        $id = spl_object_id($cell);
        if (isset($indexed[$id])) {
            return $indexed[$id];
        }

        $idx           = \count($ordered);
        $ordered[]     = $cell;
        $indexed[$id]  = $idx;

        foreach ($cell->refs as $ref) {
            self::dfs($ref, $ordered, $indexed);
        }

        return $idx;
    }

    /**
     * @param array<int, int> $indexed
     */
    private static function encodeCell(Cell $cell, array $indexed): string
    {
        $refs      = \count($cell->refs);
        $d1        = $refs;
        $byteCount = (int) ceil($cell->bitLength / 8);
        $remainder = $cell->bitLength % 8;
        $d2        = $byteCount * 2 - (0 !== $remainder ? 1 : 0);

        $data = $cell->bits;
        if (0 !== $remainder) {
            $lastIdx        = \strlen($data) - 1;
            $marker         = 1 << (7 - $remainder);
            $data[$lastIdx] = \chr((\ord($data[$lastIdx]) | $marker) & 0xFF);
        }

        $refBytes = '';
        foreach ($cell->refs as $ref) {
            $refIdx = $indexed[spl_object_id($ref)] ?? null;
            if (null === $refIdx) {
                throw new LogicException('BOC encoder: child ref missing from index map (DFS bug)');
            }
            $refBytes .= \chr($refIdx & 0xFF);
        }

        return \chr($d1 & 0xFF) . \chr($d2 & 0xFF) . $data . $refBytes;
    }

    private static function packBigEndian(int $value, int $bytes): string
    {
        $out = '';
        for ($i = $bytes - 1; $i >= 0; --$i) {
            $out .= \chr(($value >> ($i * 8)) & 0xFF);
        }

        return $out;
    }
}
