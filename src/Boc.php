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

    public static function decodeBase64(string $b64): Cell
    {
        $bytes = base64_decode($b64, true);
        if (false === $bytes) {
            throw new RuntimeException('BOC decoder: input is not valid base64');
        }

        return self::decode($bytes);
    }

    public static function decode(string $bytes): Cell
    {
        $len = \strlen($bytes);
        if ($len < 9 || self::MAGIC !== substr($bytes, 0, 4)) {
            throw new RuntimeException('BOC decoder: bad magic / truncated header');
        }

        $flags          = \ord($bytes[4]);
        $offsetByteSize = \ord($bytes[5]);
        $cellCount      = \ord($bytes[6]);
        $rootCount      = \ord($bytes[7]);
        $refSize        = $flags & 0x07;
        $hasCrc32c      = 0 !== (($flags >> 6) & 1);

        if ($refSize < 1 || $offsetByteSize < 1) {
            throw new RuntimeException('BOC decoder: unsupported ref_size / offset_size');
        }

        $cursor = 9 + $offsetByteSize + $rootCount * $refSize;
        if ($cursor > $len) {
            throw new RuntimeException('BOC decoder: header overruns buffer');
        }

        $rootIndex = $rootCount > 0 ? self::readBigEndian($bytes, 9 + $offsetByteSize, $refSize) : 0;

        $payloadEnd = $hasCrc32c ? $len - 4 : $len;
        if ($hasCrc32c && strrev(hash('crc32c', substr($bytes, 0, $payloadEnd), true)) !== substr($bytes, $payloadEnd, 4)) {
            throw new RuntimeException('BOC decoder: CRC32C mismatch');
        }

        $descriptors = [];
        for ($i = 0; $i < $cellCount; ++$i) {
            if ($cursor + 2 > $payloadEnd) {
                throw new RuntimeException('BOC decoder: descriptor overruns buffer');
            }
            $d1   = \ord($bytes[$cursor]);
            $d2   = \ord($bytes[$cursor + 1]);
            $cursor += 2;

            $refs      = $d1 & 0x07;
            $dataBytes = ($d2 >> 1) + ($d2 & 1);
            $full      = 0 === ($d2 & 1);

            if ($cursor + $dataBytes + $refs * $refSize > $payloadEnd) {
                throw new RuntimeException('BOC decoder: cell body overruns buffer');
            }
            $data = substr($bytes, $cursor, $dataBytes);
            $cursor += $dataBytes;

            $refIndices = [];
            for ($r = 0; $r < $refs; ++$r) {
                $refIndices[] = self::readBigEndian($bytes, $cursor, $refSize);
                $cursor += $refSize;
            }

            [$bits, $bitLength] = self::reconstructBits($data, $dataBytes, $full);
            $descriptors[$i]    = ['bits' => $bits, 'bitLength' => $bitLength, 'refs' => $refIndices];
        }

        if ($rootIndex < 0 || $rootIndex >= $cellCount) {
            throw new RuntimeException('BOC decoder: root index out of range');
        }

        $memo       = [];
        $inProgress = [];

        return self::buildCell($rootIndex, $descriptors, $memo, $inProgress, $cellCount);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function reconstructBits(string $data, int $dataBytes, bool $full): array
    {
        if ($full) {
            return [$data, $dataBytes * 8];
        }

        $lastIdx  = $dataBytes - 1;
        $lastByte = \ord($data[$lastIdx]);
        $marker   = $lastByte & -$lastByte;
        if (0 === $marker) {
            throw new RuntimeException('BOC decoder: completion marker missing in partial cell');
        }
        $p         = (int) log($marker, 2);
        $remainder = 7 - $p;
        $bitLength = $lastIdx * 8 + $remainder;

        $mask          = 0 === $remainder ? 0 : (0xFF << (8 - $remainder)) & 0xFF;
        $data[$lastIdx] = \chr($lastByte & $mask);

        return [$data, $bitLength];
    }

    private static function readBigEndian(string $bytes, int $offset, int $size): int
    {
        $value = 0;
        for ($i = 0; $i < $size; ++$i) {
            $value = ($value << 8) | \ord($bytes[$offset + $i]);
        }

        return $value;
    }

    /**
     * @param array<int, array{bits: string, bitLength: int, refs: list<int>}> $descriptors
     * @param array<int, Cell>                                                 $memo
     * @param array<int, bool>                                                 $inProgress
     */
    private static function buildCell(int $index, array $descriptors, array &$memo, array &$inProgress, int $cellCount): Cell
    {
        if (isset($memo[$index])) {
            return $memo[$index];
        }
        if (isset($inProgress[$index])) {
            throw new RuntimeException('BOC decoder: cyclic ref detected');
        }
        if (!isset($descriptors[$index])) {
            throw new RuntimeException('BOC decoder: ref index out of range');
        }
        $inProgress[$index] = true;

        $descriptor = $descriptors[$index];
        $childCells = [];
        foreach ($descriptor['refs'] as $refIdx) {
            if ($refIdx < 0 || $refIdx >= $cellCount) {
                throw new RuntimeException('BOC decoder: ref index out of range');
            }
            $childCells[] = self::buildCell($refIdx, $descriptors, $memo, $inProgress, $cellCount);
        }

        $cell = new Cell($descriptor['bits'], $descriptor['bitLength'], new CellRefList(...$childCells));
        unset($inProgress[$index]);

        return $memo[$index] = $cell;
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
