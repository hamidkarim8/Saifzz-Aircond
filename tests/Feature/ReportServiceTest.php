<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
    }

    private function service(): ReportService
    {
        return app(ReportService::class);
    }

    private function visitFor(Client $client, string $type = 'Cleaning', string $visitDate = '2026-06-10', ?string $nextDate = null): ServiceVisit
    {
        $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => $visitDate, 'warranty_months' => 0]);
        ServiceLine::create([
            'visit_id' => $visit->id,
            'service_type' => $type,
            'units' => 1,
            'rate' => 100,
            'discount' => 0,
            'next_service_date' => $nextDate,
        ]);

        return $visit;
    }

    private function txn(ServiceVisit $visit, float $amount, string $status, ?string $paidAt, string $id): Transaction
    {
        return Transaction::create([
            'txn_id' => $id,
            'visit_id' => $visit->id,
            'amount' => $amount,
            'method' => 'Cash',
            'status' => $status,
            'paid_at' => $paidAt,
        ]);
    }

    public function test_revenue_counts_only_paid_and_current_month_with_mom(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->txn($this->visitFor($c), 200, 'paid', '2026-06-10 09:00:00', 'TXN-1'); // this month
        $this->txn($this->visitFor($c), 100, 'paid', '2026-05-10 09:00:00', 'TXN-2'); // last month
        $this->txn($this->visitFor($c), 999, 'pending', null, 'TXN-3');               // excluded

        $k = $this->service()->kpis();

        $this->assertSame(200.0, $k['revenue_month']);
        $this->assertSame(300.0, $k['revenue_all_time']);
        $this->assertSame(100, $k['revenue_mom_pct']); // (200-100)/100
    }

    public function test_mom_is_null_when_no_prior_month_revenue(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->txn($this->visitFor($c), 200, 'paid', '2026-06-10 09:00:00', 'TXN-1');

        $this->assertNull($this->service()->kpis()['revenue_mom_pct']);
    }

    public function test_clients_this_month_delta(): void
    {
        Client::create(['name' => 'New1', 'phone' => '011-22334455', 'address' => 'X']);
        Client::create(['name' => 'New2', 'phone' => '011-22334456', 'address' => 'X']);
        $old = Client::create(['name' => 'Old', 'phone' => '011-22334457', 'address' => 'X']);
        DB::table('clients')->where('id', $old->id)->update(['created_at' => '2026-04-01 09:00:00']);

        $k = $this->service()->kpis();

        $this->assertSame(3, $k['total_clients']);
        $this->assertSame(2, $k['clients_this_month']);
    }

    public function test_pending_reminders_kpi_reflects_reminder_service(): void
    {
        $c = Client::create(['name' => 'Due', 'phone' => '011-22334455', 'address' => 'X']);
        $this->visitFor($c, 'Cleaning', '2026-03-01', '2026-05-20'); // overdue next-service

        $this->assertSame(1, $this->service()->kpis()['pending_reminders']);
    }

    public function test_services_by_type_counts_and_respects_period(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->txn($this->visitFor($c, 'Cleaning', '2026-06-10'), 100, 'paid', '2026-06-10 09:00:00', 'TXN-1');
        $this->txn($this->visitFor($c, 'Cleaning', '2026-06-12'), 100, 'paid', '2026-06-12 09:00:00', 'TXN-2');
        $this->txn($this->visitFor($c, 'Repair', '2026-05-20'), 100, 'paid', '2026-05-20 09:00:00', 'TXN-3'); // last month

        $all = $this->service()->servicesByType('all');
        $this->assertSame([['type' => 'Cleaning', 'count' => 2], ['type' => 'Repair', 'count' => 1]], $all);

        $month = $this->service()->servicesByType('month');
        $this->assertSame([['type' => 'Cleaning', 'count' => 2]], $month); // Repair excluded (last month)

        $this->assertSame([], $this->service()->servicesByType('today')); // nothing dated today
    }

    public function test_services_by_type_counts_only_paid_transactions(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);

        $this->txn($this->visitFor($c, 'Cleaning', '2026-06-10'), 100, 'paid', '2026-06-10 09:00:00', 'TXN-1');
        $this->txn($this->visitFor($c, 'Cleaning', '2026-06-11'), 100, 'pending', null, 'TXN-2');
        $this->txn($this->visitFor($c, 'Repair', '2026-06-12'), 100, 'failed', null, 'TXN-3');
        $t = $this->txn($this->visitFor($c, 'Repair', '2026-06-13'), 100, 'paid', '2026-06-13 09:00:00', 'TXN-4');
        $t->forceFill(['status' => 'void', 'voided_at' => now()])->save();
        $this->visitFor($c, 'Gas Top-Up', '2026-06-14'); // no transaction row at all

        $result = $this->service()->servicesByType('all');

        // Only TXN-1 (Cleaning) is paid: TXN-2 pending, TXN-3 failed, TXN-4 paid-then-voided,
        // and the Gas Top-Up visit has no transaction row at all (default 'pending').
        $this->assertSame([['type' => 'Cleaning', 'count' => 1]], $result);
    }

    public function test_transactions_respect_period_and_are_newest_first(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->txn($this->visitFor($c), 50, 'paid', '2026-06-10 09:00:00', 'TXN-A');
        $this->txn($this->visitFor($c), 60, 'paid', '2026-06-14 09:00:00', 'TXN-B');
        $this->txn($this->visitFor($c), 70, 'paid', '2026-05-10 09:00:00', 'TXN-C'); // last month

        $month = $this->service()->transactions('month');
        $this->assertCount(2, $month);
        $this->assertSame('TXN-B', $month[0]['txn_id']); // newest first
        $this->assertSame('TXN-A', $month[1]['txn_id']);

        $this->assertCount(3, $this->service()->transactions('all'));
    }

    public function test_transactions_include_service_type(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->txn($this->visitFor($c, 'Gas Top-Up'), 80, 'paid', '2026-06-10 09:00:00', 'TXN-ST');

        $rows = $this->service()->transactions('all');
        $this->assertArrayHasKey('service_type', $rows[0]);
        $this->assertSame('Gas Top-Up', $rows[0]['service_type']);
    }

    // ── Technician scoping tests ────────────────────────────────────────────

    private function paidVisitFor(int $techId, float $amount): void
    {
        $client = Client::create(['name' => 'C'.$techId.'-'.uniqid(), 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date'       => now()->toDateString(),
            'warranty_months'  => 0,
            'total_amount'     => $amount,
            'created_by'       => $techId,
            'technician_id'    => $techId,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning',
            'units'        => 1,
            'rate'         => $amount,
            'discount'     => 0,
        ]);
        $visit->transaction()->create([
            'txn_id'   => 'TXN-'.now()->format('Ymd').'-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount'   => $amount,
            'method'   => 'Cash',
            'status'   => 'paid',
            'paid_at'  => now(),
        ]);
    }

    public function test_transactions_scoped_to_technician(): void
    {
        $alice = \App\Models\User::factory()->technician()->create();
        $bob   = \App\Models\User::factory()->technician()->create();
        $this->paidVisitFor($alice->id, 100);
        $this->paidVisitFor($bob->id, 200);

        $service = app(\App\Services\Reports\ReportService::class);
        $rows = $service->transactions('all', null, $alice->id);

        $this->assertCount(1, $rows);
        $this->assertSame(100.0, $rows[0]['amount']);
    }

    public function test_kpis_revenue_scoped_to_technician(): void
    {
        $alice = \App\Models\User::factory()->technician()->create();
        $bob   = \App\Models\User::factory()->technician()->create();
        $this->paidVisitFor($alice->id, 100);
        $this->paidVisitFor($bob->id, 200);

        $service = app(\App\Services\Reports\ReportService::class);
        $this->assertSame(100.0, $service->kpis($alice->id)['revenue_all_time']);
        $this->assertSame(300.0, $service->kpis(null)['revenue_all_time']);
    }

    // ── Receivables / Aging tests ───────────────────────────────────────────────

    private function pendingVisit(Client $client, int $daysAgo, float $amount, string $txnId, ?int $technicianId = null): void
    {
        $visit = ServiceVisit::create([
            'client_id'      => $client->id,
            'visit_date'     => now()->subDays($daysAgo)->toDateString(),
            'warranty_months'=> 0,
            'technician_id'  => $technicianId,
        ]);
        Transaction::create([
            'txn_id'   => $txnId,
            'visit_id' => $visit->id,
            'amount'   => $amount,
            'method'   => 'Cash',
            'status'   => 'pending',
            'paid_at'  => null,
        ]);
    }

    public function test_receivables_empty_when_no_pending_transactions(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        // Only a paid transaction — must not appear in receivables
        $visit = ServiceVisit::create(['client_id' => $c->id, 'visit_date' => now()->subDays(5)->toDateString(), 'warranty_months' => 0]);
        Transaction::create([
            'txn_id' => 'TXN-PAID', 'visit_id' => $visit->id, 'amount' => 100,
            'method' => 'Cash', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $result = $this->service()->receivables();

        $this->assertEmpty($result['items']);
        $this->assertSame(0.0, $result['total_outstanding']);
        foreach ($result['buckets'] as $b) {
            $this->assertSame(0, $b['count']);
            $this->assertSame(0.0, $b['total']);
        }
    }

    public function test_receivables_buckets_visits_by_age(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $this->pendingVisit($c, 10,  100.0, 'TXN-10');   // 10 days → Current  (0–30)
        $this->pendingVisit($c, 45,  200.0, 'TXN-45');   // 45 days → Overdue  (31–60)
        $this->pendingVisit($c, 75,  300.0, 'TXN-75');   // 75 days → Late     (61–90)
        $this->pendingVisit($c, 120, 400.0, 'TXN-120');  // 120 days → Critical (91+)

        $result = $this->service()->receivables();

        $this->assertCount(4, $result['items']);
        $this->assertSame(1000.0, $result['total_outstanding']);

        [$current, $overdue, $late, $critical] = $result['buckets'];
        $this->assertSame(1,     $current['count']);  $this->assertSame(100.0, $current['total']);
        $this->assertSame(1,     $overdue['count']);  $this->assertSame(200.0, $overdue['total']);
        $this->assertSame(1,     $late['count']);     $this->assertSame(300.0, $late['total']);
        $this->assertSame(1,     $critical['count']); $this->assertSame(400.0, $critical['total']);

        // Items sorted oldest first
        $this->assertSame('TXN-120', $result['items'][0]['txn_id']);
        $this->assertSame(120,       $result['items'][0]['days_outstanding']);
        $this->assertSame('TXN-10',  $result['items'][3]['txn_id']);
    }

    public function test_receivables_scoped_to_technician(): void
    {
        $alice = \App\Models\User::factory()->technician()->create();
        $bob   = \App\Models\User::factory()->technician()->create();
        $c     = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);

        $this->pendingVisit($c, 10, 100.0, 'TXN-ALICE', $alice->id);
        $this->pendingVisit($c, 20, 200.0, 'TXN-BOB',   $bob->id);

        $result = $this->service()->receivables($alice->id);

        $this->assertCount(1, $result['items']);
        $this->assertSame('TXN-ALICE', $result['items'][0]['txn_id']);
        $this->assertSame(100.0, $result['total_outstanding']);
    }

    public function test_receivables_null_technician_id_returns_all(): void
    {
        $alice = \App\Models\User::factory()->technician()->create();
        $bob   = \App\Models\User::factory()->technician()->create();
        $c     = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);

        $this->pendingVisit($c, 10, 100.0, 'TXN-1', $alice->id);
        $this->pendingVisit($c, 20, 200.0, 'TXN-2', $bob->id);

        $result = $this->service()->receivables(null);

        $this->assertCount(2, $result['items']);
        $this->assertSame(300.0, $result['total_outstanding']);
    }

    public function test_transactions_custom_date_range_overrides_period(): void
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-2222222', 'address' => 'KL']);
        $visit1 = $this->visitFor($client, 'Cleaning', '2026-06-05');
        $this->txn($visit1, 100, 'paid', '2026-06-05 10:00:00', 'TXN-1');
        $visit2 = $this->visitFor($client, 'Cleaning', '2026-06-20');
        $this->txn($visit2, 200, 'paid', '2026-06-20 10:00:00', 'TXN-2');

        $rows = $this->service()->transactions(
            'all', null, null, null,
            Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('TXN-1', $rows[0]['txn_id']);
    }
}
