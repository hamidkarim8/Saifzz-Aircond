<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\ReminderContact;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Services\Reminders\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze "today" so next-service windows are deterministic. June has 30 days,
        // so end-of-month = 2026-06-30.
        $this->travelTo(Carbon::parse('2026-06-15'));
    }

    private function makeClient(string $name = 'Client'): Client
    {
        return Client::create(['name' => $name, 'phone' => '011-22334455', 'address' => 'A']);
    }

    private function addLine(Client $client, ?string $nextDate, string $type = 'Cleaning', string $visitDate = '2026-05-01'): ServiceLine
    {
        $visit = ServiceVisit::create([
            'client_id' => $client->id,
            'visit_date' => $visitDate,
            'warranty_months' => 0,
        ]);

        return ServiceLine::create([
            'visit_id' => $visit->id,
            'service_type' => $type,
            'units' => 1,
            'rate' => 100,
            'discount' => 0,
            'next_service_date' => $nextDate,
        ]);
    }

    private function dueList(): array
    {
        return app(ReminderService::class)->dueList();
    }

    public function test_partitions_overdue_and_due_this_month_and_excludes_future(): void
    {
        $this->addLine($this->makeClient('Overdue Olivia'), '2026-06-01');   // before today
        $this->addLine($this->makeClient('Due Dana'), '2026-06-20');         // within this month
        $this->addLine($this->makeClient('Future Fariz'), '2026-07-10');     // next month — excluded

        $r = $this->dueList();

        $this->assertCount(1, $r['overdue']);
        $this->assertSame('Overdue Olivia', $r['overdue'][0]['name']);
        $this->assertCount(1, $r['due_this_month']);
        $this->assertSame('Due Dana', $r['due_this_month'][0]['name']);
        $this->assertSame(1, $r['stats']['overdue']);
        $this->assertSame(1, $r['stats']['due_this_month']);
    }

    public function test_max_next_service_date_wins_over_older_line(): void
    {
        $client = $this->makeClient('Multi Maya');
        $this->addLine($client, '2026-06-01', 'Cleaning', '2026-03-01');  // older recommendation
        $this->addLine($client, '2026-06-20', 'Cleaning', '2026-05-01');  // newer — should win

        $r = $this->dueList();

        // MAX(next_service_date) = 2026-06-20 → due this month, not overdue.
        $this->assertCount(0, $r['overdue']);
        $this->assertCount(1, $r['due_this_month']);
        $this->assertSame('2026-06-20', $r['due_this_month'][0]['next_due']);
    }

    public function test_client_with_only_null_next_service_dates_is_excluded(): void
    {
        // Repair lines strip next_service_date (R2) → nothing to remind about.
        $this->addLine($this->makeClient('Repair Rina'), null, 'Repair');

        $r = $this->dueList();

        $this->assertCount(0, $r['overdue']);
        $this->assertCount(0, $r['due_this_month']);
    }

    public function test_soft_deleted_client_is_excluded(): void
    {
        $client = $this->makeClient('Gone Ghani');
        $this->addLine($client, '2026-06-01');
        $client->delete();

        $r = $this->dueList();

        $this->assertCount(0, $r['overdue']);
        $this->assertCount(0, $r['due_this_month']);
    }

    public function test_contacted_flag_surfaces_when_a_row_exists(): void
    {
        $client = $this->makeClient('Contacted Cik');
        $this->addLine($client, '2026-06-01');
        ReminderContact::create(['client_id' => $client->id, 'contacted_at' => now()]);

        $r = $this->dueList();

        $this->assertTrue($r['overdue'][0]['contacted']);
        $this->assertSame(1, $r['stats']['contacted']);
    }

    public function test_last_service_date_is_the_most_recent_visit(): void
    {
        $client = $this->makeClient('History Hana');
        $this->addLine($client, '2026-06-20', 'Cleaning', '2026-03-01');
        $this->addLine($client, '2026-06-25', 'Cleaning', '2026-05-10'); // most recent visit

        $r = $this->dueList();

        $this->assertSame('2026-05-10', $r['due_this_month'][0]['last_service_date']);
    }

    public function test_service_type_and_units_are_included(): void
    {
        $client = $this->makeClient('Fields Fatimah');
        $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
        ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning', 'units' => 3, 'rate' => 100, 'discount' => 0, 'next_service_date' => '2026-06-20']);

        $r = $this->dueList();

        $item = $r['due_this_month'][0];
        $this->assertSame('Cleaning', $item['service_type']);
        $this->assertSame(3, $item['units']);
    }

    public function test_service_type_reflects_line_with_latest_next_service_date(): void
    {
        $client = $this->makeClient('Latest Laila');
        $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
        ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning', 'units' => 1, 'rate' => 100, 'discount' => 0, 'next_service_date' => '2026-06-10']);
        ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Installation', 'units' => 1, 'rate' => 200, 'discount' => 0, 'next_service_date' => '2026-06-25']);

        $r = $this->dueList();

        // Installation has the later next_service_date — should win
        $this->assertSame('Installation', $r['due_this_month'][0]['service_type']);
    }

    // ── client_units-based tests ─────────────────────────────────────────────

    private function makeUnit(Client $client, string $type, ?string $nextDate, string $serviceType = 'Cleaning'): ClientUnit
    {
        return ClientUnit::create([
            'client_id'         => $client->id,
            'label'             => $type . ' 1',
            'unit_type'         => $type,
            'is_active'         => true,
            'next_service_date' => $nextDate,
            'next_service_type' => $nextDate ? $serviceType : null,
        ]);
    }

    public function test_reminder_sources_from_client_units(): void
    {
        $client = $this->makeClient('Unit Client');
        $this->makeUnit($client, 'Wall Mounted', '2026-06-20'); // due this month

        $r = $this->dueList();

        $this->assertCount(1, $r['due_this_month']);
        $this->assertSame('Unit Client', $r['due_this_month'][0]['name']);
        $this->assertSame('2026-06-20', $r['due_this_month'][0]['next_due']);
    }

    public function test_reminder_service_type_from_unit(): void
    {
        $client = $this->makeClient('Type Client');
        $this->makeUnit($client, 'Wall Mounted', '2026-06-20', 'Installation');

        $r = $this->dueList();

        $this->assertSame('Installation', $r['due_this_month'][0]['service_type']);
    }

    public function test_reminder_units_count_active_units_due(): void
    {
        $client = $this->makeClient('Multi Client');
        $this->makeUnit($client, 'Wall Mounted', '2026-06-20');
        $this->makeUnit($client, 'Cassette', '2026-06-25');
        $this->makeUnit($client, 'Wall Mounted', null); // no date — not due

        $r = $this->dueList();

        $this->assertSame(2, $r['due_this_month'][0]['units']);
    }

    public function test_inactive_unit_excluded_from_reminders(): void
    {
        $client = $this->makeClient('Inactive Client');
        ClientUnit::create([
            'client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted',
            'is_active' => false, 'next_service_date' => '2026-06-20', 'next_service_type' => 'Cleaning',
        ]);

        $r = $this->dueList();

        $this->assertCount(0, $r['due_this_month']);
    }

    public function test_fallback_to_service_lines_for_clients_without_units(): void
    {
        // Client with service_line next_service_date but no client_units records
        $client = $this->makeClient('Legacy Client');
        $visit = \App\Models\ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
        \App\Models\ServiceLine::create([
            'visit_id' => $visit->id, 'service_type' => 'Cleaning',
            'units' => 1, 'rate' => 80, 'discount' => 0, 'next_service_date' => '2026-06-20',
        ]);

        $r = $this->dueList();

        // Legacy client still appears via service_line fallback
        $this->assertCount(1, $r['due_this_month']);
        $this->assertSame('Legacy Client', $r['due_this_month'][0]['name']);
    }
}
