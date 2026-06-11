<?php

namespace Tests\Feature;

use App\Models\Client;
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
}
