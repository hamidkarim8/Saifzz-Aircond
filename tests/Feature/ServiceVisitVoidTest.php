<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_accepts_void_fields(): void
    {
        $client = Client::create(['name' => 'A', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60]);
        $actor = User::factory()->create();

        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-001',
            'amount' => 60,
            'method' => 'Cash',
            'status' => 'void',
            'void_reason' => 'Billed by mistake',
            'voided_at' => now(),
            'voided_by' => $actor->id,
        ]);

        $fresh = Transaction::find($txn->id);
        $this->assertSame('Billed by mistake', $fresh->void_reason);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->voided_at);
        $this->assertSame($actor->id, $fresh->voided_by);
    }
}
