<?php

namespace App\Services\Payments\Support;

final class Checksum
{
    /**
     * HMAC-SHA256 over ordered field values joined by '|'.
     * TODO(go-live): confirm join char + field ordering against BayarCash v3 docs.
     */
    public static function make(array $orderedValues, string $secret): string
    {
        $payload = implode('|', array_map(static fn ($v) => (string) $v, $orderedValues));

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(array $orderedValues, string $given, string $secret): bool
    {
        return hash_equals(self::make($orderedValues, $secret), (string) $given);
    }
}
