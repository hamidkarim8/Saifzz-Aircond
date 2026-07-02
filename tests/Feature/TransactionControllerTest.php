<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    private function paidTxnOn(string $date, int $tenantId): Transaction
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = ServiceVisit::create([
            'client_id' => $client->id, 'visit_date' => $date, 'warranty_months' => 0,
            'total_amount' => 60, 'tenant_id' => $tenantId,
        ]);
        ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning', 'units' => 1, 'rate' => 60, 'discount' => 0]);

        return Transaction::create([
            'txn_id' => 'TXN-' . str_replace('-', '', $date) . '-001',
            'visit_id' => $visit->id, 'amount' => 60, 'method' => 'Cash',
            'status' => 'paid', 'paid_at' => $date . ' 10:00:00',
        ]);
    }

    public function test_date_range_filters_out_transactions_outside_it(): void
    {
        $boss = $this->boss();
        $inRange = $this->paidTxnOn('2026-06-05', $boss->tenantId());
        $this->paidTxnOn('2026-06-20', $boss->tenantId());

        $this->actingAs($boss)
            ->get(route('transactions.index', ['date_from' => '2026-06-01', 'date_to' => '2026-06-10']))
            ->assertInertia(fn ($page) => $page
                ->has('transactions', 1)
                ->where('transactions.0.txn_id', $inRange->txn_id));
    }

    public function test_date_to_before_date_from_is_rejected(): void
    {
        $this->actingAs($this->boss())
            ->get(route('transactions.index', ['date_from' => '2026-06-10', 'date_to' => '2026-06-01']))
            ->assertSessionHasErrors('date_to');
    }
}
