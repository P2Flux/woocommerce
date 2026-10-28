<?php

declare(strict_types=1);

namespace P2FluxWC\Vendor\P2Flux;

/**
 * Keccak-256 (Ethereum's padding, not SHA3-256's), small and pure PHP: no extension, no dependency.
 * Used to check a merchant_ref commitment (P2FluxClient::checkMerchantRef) and nothing else.
 * Needs 64-bit PHP. ponytail: plain int lanes, fine for short proofs, not for hashing megabytes.
 */
final class Keccak
{
    private const RC = [
        0x0000000000000001, 0x0000000000008082, -0x7fffffffffff7f76, -0x7fffffff7fff8000, 0x000000000000808b, 0x0000000080000001,
        -0x7fffffff7fff7f7f, -0x7fffffffffff7ff7, 0x000000000000008a, 0x0000000000000088, 0x0000000080008009, 0x000000008000000a,
        0x000000008000808b, -0x7fffffffffffff75, -0x7fffffffffff7f77, -0x7fffffffffff7ffd, -0x7fffffffffff7ffe, -0x7fffffffffffff80,
        0x000000000000800a, -0x7fffffff7ffffff6, -0x7fffffff7fff7f7f, -0x7fffffffffff7f80, 0x0000000080000001, -0x7fffffff7fff7ff8,
    ];
    // Rotation offsets, indexed x + 5y.
    private const ROT = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];

    /** Keccak-256 of $data, as 0x-prefixed lowercase hex. */
    public static function hash256(string $data): string
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('Keccak needs 64-bit PHP');
        }
        $rate = 136;
        $padded = $data . "\x01" . str_repeat("\x00", $rate - 1 - strlen($data) % $rate);
        $padded[strlen($padded) - 1] = chr(ord($padded[strlen($padded) - 1]) | 0x80);
        $a = array_fill(0, 25, 0);
        foreach (str_split($padded, $rate) as $block) {
            foreach (array_values(unpack('P17', $block)) as $i => $lane) {
                $a[$i] ^= $lane;
            }
            self::permute($a);
        }

        return '0x' . bin2hex(pack('P4', $a[0], $a[1], $a[2], $a[3]));
    }

    private static function rotl(int $v, int $n): int
    {
        return $n === 0 ? $v : ($v << $n) | (($v >> (64 - $n)) & ((1 << $n) - 1));
    }

    /** @param array<int, int> $a */
    private static function permute(array &$a): void
    {
        $b = array_fill(0, 25, 0);
        foreach (self::RC as $rc) {
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $a[$x] ^ $a[$x + 5] ^ $a[$x + 10] ^ $a[$x + 15] ^ $a[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $c[($x + 4) % 5] ^ self::rotl($c[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$x + $y] ^= $d;
                }
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = self::rotl($a[$x + 5 * $y], self::ROT[$x + 5 * $y]);
                }
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$x + $y] = $b[$x + $y] ^ (~$b[($x + 1) % 5 + $y] & $b[($x + 2) % 5 + $y]);
                }
            }
            $a[0] ^= $rc;
        }
    }
}
