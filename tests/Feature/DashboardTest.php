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

    public function test_technician_without_view_reports_sees_launcher(): void
    {
        $this->actingAs($this->user(['view_clients']))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('canReport', false)
                ->missing('report')
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

        $response = $this->actingAs($this->user(['export_data']))
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

        $csv = $this->actingAs($this->user(['export_data']))
            ->get(route('reports.transactions.export', ['period' => 'today']))
            ->streamedContent();

        // Header present, but the June-10 txn is not "today" (2026-06-15).
        $this->assertStringContainsString('Txn ID', $csv);
        $this->assertStringNotContainsString('TXN-1', $csv);
    }
}
