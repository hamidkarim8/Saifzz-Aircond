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
        $this->visitFor($c, 'Cleaning', '2026-06-10');
        $this->visitFor($c, 'Cleaning', '2026-06-12');
        $this->visitFor($c, 'Repair', '2026-05-20'); // last month

        $all = $this->service()->servicesByType('all');
        $this->assertSame([['type' => 'Cleaning', 'count' => 2], ['type' => 'Repair', 'count' => 1]], $all);

        $month = $this->service()->servicesByType('month');
        $this->assertSame([['type' => 'Cleaning', 'count' => 2]], $month); // Repair excluded (last month)

        $this->assertSame([], $this->service()->servicesByType('today')); // nothing dated today
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
}
