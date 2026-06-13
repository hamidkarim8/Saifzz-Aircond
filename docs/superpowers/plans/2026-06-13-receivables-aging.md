# Receivables / Aging Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Embed an outstanding-receivables aging section into the existing Dashboard page, showing unpaid service visits grouped into 0–30 / 31–60 / 61–90 / 90+ day buckets with a drill-down link to the service record.

**Architecture:** New `ReportService::receivables()` method queries pending transactions joined to visits + clients, computes `days_outstanding` from PHP's `now()` (mock-safe for tests), buckets in PHP. `DashboardController` adds `report.receivables` gated by `collect_payment` permission, scoped to the user's own visits when not all-data. `Dashboard.vue` renders a new section below the transactions table.

**Tech Stack:** Laravel 13 · PostgreSQL · Inertia/Vue 3 · Tailwind · PHPUnit

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `app/Services/Reports/ReportService.php` | Modify | Add `receivables()` method |
| `app/Http/Controllers/DashboardController.php` | Modify | Pass `report.receivables` to Inertia |
| `resources/js/Pages/Dashboard.vue` | Modify | Render aging bucket cards + table |
| `tests/Feature/ReportServiceTest.php` | Modify | Unit tests for `receivables()` |
| `tests/Feature/DashboardTest.php` | Modify | Feature tests for dashboard endpoint |

---

## Task 1: Service layer — `ReportService::receivables()`

**Files:**
- Modify: `app/Services/Reports/ReportService.php`
- Modify: `tests/Feature/ReportServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Feature/ReportServiceTest.php` before the closing `}`:

```php
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
```

- [ ] **Step 2: Run tests — expect 4 failures**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_receivables
```

Expected: 4 failures with `Call to undefined method App\Services\Reports\ReportService::receivables()`

- [ ] **Step 3: Implement `receivables()` in `ReportService`**

Add this method to `app/Services/Reports/ReportService.php` before the closing `}`:

```php
/**
 * Outstanding (pending) transactions grouped into 4 aging buckets.
 * days_outstanding is computed from PHP's now() so tests using travelTo() work correctly.
 * When $technicianId is provided, only visits assigned to that technician are returned.
 *
 * @return array{buckets: list<array{label:string,days_from:int,days_to:int|null,count:int,total:float}>, items: list<array<string,mixed>>, total_outstanding: float}
 */
public function receivables(?int $technicianId = null): array
{
    $today = now()->toDateString();

    $rows = DB::table('transactions as t')
        ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
        ->join('clients as c', 'c.id', '=', 'sv.client_id')
        ->whereNull('c.deleted_at')
        ->where('t.status', 'pending')
        ->when($technicianId !== null, fn ($q) => $q->where('sv.technician_id', $technicianId))
        ->select([
            'sv.id as visit_id',
            't.txn_id',
            'c.name as client_name',
            'c.serial_no',
            'sv.visit_date',
            't.amount',
            DB::raw("(DATE '{$today}' - sv.visit_date::date) AS days_outstanding"),
        ])
        ->orderByRaw("(DATE '{$today}' - sv.visit_date::date) DESC")
        ->get();

    $buckets = [
        ['label' => 'Current',  'days_from' => 0,  'days_to' => 30,  'count' => 0, 'total' => 0.0],
        ['label' => 'Overdue',  'days_from' => 31, 'days_to' => 60,  'count' => 0, 'total' => 0.0],
        ['label' => 'Late',     'days_from' => 61, 'days_to' => 90,  'count' => 0, 'total' => 0.0],
        ['label' => 'Critical', 'days_from' => 91, 'days_to' => null,'count' => 0, 'total' => 0.0],
    ];
    $items            = [];
    $totalOutstanding = 0.0;

    foreach ($rows as $r) {
        $days             = (int) $r->days_outstanding;
        $amount           = (float) $r->amount;
        $totalOutstanding += $amount;

        $idx = match (true) {
            $days <= 30 => 0,
            $days <= 60 => 1,
            $days <= 90 => 2,
            default     => 3,
        };
        $buckets[$idx]['count']++;
        $buckets[$idx]['total'] += $amount;

        $items[] = [
            'visit_id'         => (int) $r->visit_id,
            'txn_id'           => $r->txn_id,
            'client_name'      => $r->client_name,
            'serial_no'        => $r->serial_no,
            'visit_date'       => substr((string) $r->visit_date, 0, 10),
            'amount'           => $amount,
            'days_outstanding' => $days,
        ];
    }

    foreach ($buckets as &$bucket) {
        $bucket['total'] = round($bucket['total'], 2);
    }
    unset($bucket);

    return [
        'buckets'           => $buckets,
        'items'             => $items,
        'total_outstanding' => round($totalOutstanding, 2),
    ];
}
```

- [ ] **Step 4: Run tests — expect all pass**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_receivables
```

Expected: 4 tests pass.

- [ ] **Step 5: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all existing tests still pass (new total = prior count + 4).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Reports/ReportService.php tests/Feature/ReportServiceTest.php
git commit -m "feat(reports): add receivables() aging method to ReportService"
```

---

## Task 2: Wire receivables into DashboardController

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Feature/DashboardTest.php` before the closing `}`:

```php
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
            ->where('report.receivables.total_outstanding', 100.0)
            ->count('report.receivables.items', 1)
        );
}
```

- [ ] **Step 2: Run tests — expect 3 failures**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_user_with_collect_payment_gets_receivables|test_user_without_collect_payment_gets_null_receivables|test_scoped_tech_receivables_filtered_to_own_visits"
```

Expected: 3 failures — `report.receivables` key missing.

- [ ] **Step 3: Implement controller change**

In `app/Http/Controllers/DashboardController.php`, replace the `$report` array inside `Inertia::render()`:

```php
$canReport  = $user->hasPermission('view_reports');
$canCollect = $user->hasPermission('collect_payment');

return Inertia::render('Dashboard', [
    'canReport'    => $canReport,
    'period'       => $period,
    'month'        => $month,
    'report'       => [
        'kpis'           => $reports->kpis($scopeId),
        'servicesByType' => $reports->servicesByType($period, $scopeId),
        'transactions'   => $canReport
            ? $reports->transactions($period, 50, $scopeId)
            : [],
        'receivables'    => $canCollect
            ? $reports->receivables($scopeId)
            : null,
    ],
    'appointments' => Appointment::query()
        ->visibleTo($user)
        ->with('client:id,serial_no,name')
        ->forMonth($month)
        ->orderBy('datetime')
        ->get(),
]);
```

Also declare `$canCollect` — add it right after the existing `$canReport` line (line 31 in the original):

```php
$canReport  = $user->hasPermission('view_reports');
$canCollect = $user->hasPermission('collect_payment');
```

- [ ] **Step 4: Run tests — expect all 3 pass**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_user_with_collect_payment_gets_receivables|test_user_without_collect_payment_gets_null_receivables|test_scoped_tech_receivables_filtered_to_own_visits"
```

Expected: 3 tests pass.

- [ ] **Step 5: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass (new total = prior + 3).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardTest.php
git commit -m "feat(dashboard): pass receivables payload gated by collect_payment"
```

---

## Task 3: Frontend — Dashboard.vue receivables section

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`

- [ ] **Step 1: Add aging helpers to script**

In the `<script setup>` block, after the existing `const exportUrl = ...` line, add:

```js
// ── Aging color helpers ──
const agingBucketClass = (daysFrom) => {
    if (daysFrom === 0)  return 'border-green-200 bg-green-50';
    if (daysFrom === 31) return 'border-yellow-200 bg-yellow-50';
    if (daysFrom === 61) return 'border-orange-200 bg-orange-50';
    return 'border-red-200 bg-red-50';
};
const agingTextClass = (daysFrom) => {
    if (daysFrom === 0)  return 'text-green-700';
    if (daysFrom === 31) return 'text-yellow-700';
    if (daysFrom === 61) return 'text-orange-700';
    return 'text-red-700';
};
const agingBadgeClass = (days) => {
    if (days <= 30) return 'bg-green-100 text-green-700';
    if (days <= 60) return 'bg-yellow-100 text-yellow-700';
    if (days <= 90) return 'bg-orange-100 text-orange-700';
    return 'bg-red-100 text-red-700';
};
```

Also update the `report` prop default to include `receivables`:

```js
report: { type: Object, default: () => ({ kpis: {}, servicesByType: [], transactions: [], receivables: null }) },
```

- [ ] **Step 2: Add receivables section to template**

In `<template>`, after the closing `</div>` of the `<!-- ── Recent Transactions ──` section (after `</div>` on the last line before `</AdminLayout>`), add:

```html
<!-- ── Outstanding Receivables (collect_payment only) ── -->
<div v-if="report.receivables" class="mt-5 rounded-ral border border-line bg-surface shadow-card">
    <div class="border-b border-line px-5 py-3">
        <h2 class="text-sm font-bold text-navy-800">
            Outstanding Receivables
            <span class="ml-2 font-mono font-normal text-ink-muted">— {{ money(report.receivables.total_outstanding) }}</span>
        </h2>
    </div>

    <!-- Aging bucket summary cards -->
    <div class="grid grid-cols-2 gap-4 p-5 lg:grid-cols-4">
        <div
            v-for="bucket in report.receivables.buckets"
            :key="bucket.label"
            class="rounded-ral border p-4"
            :class="agingBucketClass(bucket.days_from)"
        >
            <p class="text-xs font-medium text-ink-soft">{{ bucket.label }}</p>
            <p class="mt-1 font-mono text-base font-bold" :class="agingTextClass(bucket.days_from)">
                {{ money(bucket.total) }}
            </p>
            <p class="mt-0.5 text-xs text-ink-muted">{{ bucket.count }} {{ bucket.count === 1 ? 'visit' : 'visits' }}</p>
        </div>
    </div>

    <!-- Receivables detail table -->
    <div class="border-t border-line">
        <div v-if="report.receivables.items.length" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-5 py-3 text-left">Client</th>
                        <th class="px-5 py-3 text-left">Serial</th>
                        <th class="px-5 py-3 text-left">Visit Date</th>
                        <th class="px-5 py-3 text-left">Age</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr
                        v-for="item in report.receivables.items"
                        :key="item.txn_id"
                        class="transition hover:bg-surface-muted"
                    >
                        <td class="px-5 py-3 font-medium text-ink">{{ item.client_name }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-ink-muted">{{ item.serial_no ?? '—' }}</td>
                        <td class="px-5 py-3 text-ink-soft">{{ fmtDate(item.visit_date) }}</td>
                        <td class="px-5 py-3">
                            <span
                                class="rounded-ra px-2 py-1 text-xs font-semibold"
                                :class="agingBadgeClass(item.days_outstanding)"
                            >
                                {{ item.days_outstanding }}d
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right font-mono font-semibold text-ink">{{ money(item.amount) }}</td>
                        <td class="px-5 py-3 text-right">
                            <Link
                                :href="route('service-records.show', item.visit_id)"
                                class="text-xs font-semibold text-primary hover:text-primary-hover"
                            >
                                View →
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="px-5 py-8 text-center text-sm text-ink-muted">No outstanding payments.</p>
    </div>
</div>
```

- [ ] **Step 3: Build assets**

```bash
docker compose exec -T laravel.test npm run build
```

Expected: build completes with no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): add outstanding receivables section with aging buckets"
```

---

## Verification

After all tasks complete:

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass. New tests added: 7 (4 service + 3 dashboard).

To verify visually: log in as admin at `http://localhost:8000`, navigate to Dashboard — the Outstanding Receivables section should appear at the bottom with aging bucket cards and the detail table. Create a pending service visit (don't pay it) to confirm it appears in the Current bucket.
