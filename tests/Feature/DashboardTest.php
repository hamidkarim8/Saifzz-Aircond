<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
    }

    private function user(array $permissions): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => $permissions,
        ]);
    }

    private function paidTransaction(): void
    {
        $c = Client::create(['name' => 'A', 'phone' => '011-22334455', 'address' => 'X']);
        $visit = ServiceVisit::create(['client_id' => $c->id, 'visit_date' => '2026-06-10', 'warranty_months' => 0]);
        Transaction::create([
            'txn_id' => 'TXN-1', 'visit_id' => $visit->id, 'amount' => 250,
            'method' => 'Cash', 'status' => 'paid', 'paid_at' => '2026-06-10 09:00:00',
        ]);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_user_with_view_reports_sees_report_payload(): void
    {
        $this->paidTransaction();

        $this->actingAs($this->user(['view_reports']))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('canReport', true)
                ->where('period', 'all')
                ->has('report.kpis')
                ->has('report.servicesByType')
                ->has('report.transactions')
            );
    }

    public function test_technician_without_view_reports_sees_scoped_dashboard(): void
    {
        $this->actingAs($this->user(['view_clients']))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('canReport', false)
                ->has('report.kpis')
                ->has('report.servicesByType')
                ->where('report.transactions', [])
                ->has('appointments')
            );
    }

    public function test_technician_kpis_scoped_without_view_reports(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['view_clients']]);
        $bob   = User::factory()->technician()->create();
        $this->paidVisitFor($alice->id, 150);
        $this->paidVisitFor($bob->id, 300);

        $this->actingAs($alice)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReport', false)
                ->where('report.kpis.revenue_all_time', 150)
                ->where('report.transactions', [])
            );
    }

    public function test_export_is_forbidden_without_export_data(): void
    {
        $this->actingAs($this->user(['view_reports']))
            ->get(route('reports.transactions.export'))
            ->assertForbidden();
    }

    public function test_export_streams_csv_for_permitted_user(): void
    {
        $this->paidTransaction();

        $response = $this->actingAs($this->user(['export_data', 'view_all_data']))
            ->get(route('reports.transactions.export', ['period' => 'all']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        // "Txn ID" is quoted by fputcsv (contains a space); assert the unquoted remainder.
        $this->assertStringContainsString('Date,Client,Serial,Amount,Method,Status', $csv);
        $this->assertStringContainsString('TXN-1,2026-06-10,A,000001,250.00,Cash,paid', $csv);
    }

    public function test_export_period_filters_rows(): void
    {
        $this->paidTransaction(); // dated 2026-06-10

        $csv = $this->actingAs($this->user(['export_data', 'view_all_data']))
            ->get(route('reports.transactions.export', ['period' => 'today']))
            ->streamedContent();

        // Header present, but the June-10 txn is not "today" (2026-06-15).
        $this->assertStringContainsString('Txn ID', $csv);
        $this->assertStringNotContainsString('TXN-1', $csv);
    }

    private function paidVisitFor(int $techId, float $amount): void
    {
        static $seq = 0;
        $seq++;
        $c = Client::create(['name' => "Client{$seq}", 'phone' => "011-{$seq}0000000", 'address' => 'X']);
        $visit = ServiceVisit::create([
            'client_id' => $c->id,
            'visit_date' => now()->toDateString(),
            'warranty_months' => 0,
            'total_amount' => $amount,
            'created_by' => $techId,
            'technician_id' => $techId,
        ]);
        Transaction::create([
            'txn_id' => "TXN-SCOPE-{$seq}",
            'visit_id' => $visit->id,
            'amount' => $amount,
            'method' => 'Cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function test_dashboard_revenue_scoped_for_technician(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['view_clients', 'view_reports']]);
        $bob = User::factory()->technician()->create();
        $this->paidVisitFor($alice->id, 100);
        $this->paidVisitFor($bob->id, 200);

        $this->actingAs($alice)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.kpis.revenue_all_time', 100)
                ->where('report.kpis.pending_reminders', 0));
    }

    private function pendingVisitFor(int $techId, float $amount, int $daysAgo = 5): void
    {
        static $seq2 = 0;
        $seq2++;
        $c = Client::create(['name' => "PendingClient{$seq2}", 'phone' => "011-9{$seq2}000000", 'address' => 'X']);
        $visit = ServiceVisit::create([
            'client_id'      => $c->id,
            'visit_date'     => now()->subDays($daysAgo)->toDateString(),
            'warranty_months'=> 0,
            'technician_id'  => $techId,
        ]);
        Transaction::create([
            'txn_id'   => "TXN-PENDING-{$seq2}",
            'visit_id' => $visit->id,
            'amount'   => $amount,
            'method'   => 'Cash',
            'status'   => 'pending',
            'paid_at'  => null,
        ]);
    }

    public function test_user_with_collect_payment_gets_receivables(): void
    {
        $user = $this->user(['collect_payment']);
        $this->pendingVisitFor($user->id, 150.0);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('report.receivables')
                ->has('report.receivables.buckets')
                ->has('report.receivables.items')
                ->has('report.receivables.total_outstanding')
            );
    }

    public function test_user_without_collect_payment_gets_null_receivables(): void
    {
        $this->actingAs($this->user(['view_clients']))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('report.receivables', null)
            );
    }

    public function test_scoped_tech_receivables_filtered_to_own_visits(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['collect_payment']]);
        $bob   = User::factory()->technician()->create();

        $this->pendingVisitFor($alice->id, 100.0);
        $this->pendingVisitFor($bob->id,   200.0);

        $this->actingAs($alice)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('report.receivables.total_outstanding', 100)
                ->count('report.receivables.items', 1)
            );
    }
}
