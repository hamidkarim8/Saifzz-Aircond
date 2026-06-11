<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Support\Checksum;
use PHPUnit\Framework\TestCase;

class ChecksumTest extends TestCase
{
    public function test_make_is_deterministic_and_verify_round_trips(): void
    {
        $fields = ['STUB-ABC', 'TXN-20260611-001', '110.00', '3'];
        $sig = Checksum::make($fields, 'secret');

        $this->assertSame($sig, Checksum::make($fields, 'secret')); // deterministic
        $this->assertTrue(Checksum::verify($fields, $sig, 'secret'));
    }

    public function test_verify_rejects_tampered_fields_and_wrong_secret(): void
    {
        $fields = ['STUB-ABC', 'TXN-20260611-001', '110.00', '3'];
        $sig = Checksum::make($fields, 'secret');

        $this->assertFalse(Checksum::verify(['STUB-ABC', 'TXN-20260611-001', '999.00', '3'], $sig, 'secret'));
        $this->assertFalse(Checksum::verify($fields, $sig, 'other-secret'));
        $this->assertFalse(Checksum::verify($fields, 'garbage', 'secret'));
    }
}
