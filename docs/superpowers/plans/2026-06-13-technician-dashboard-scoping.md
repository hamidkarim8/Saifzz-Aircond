# Technician Dashboard Scoping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Technicians always see their own KPIs on the dashboard; `view_reports` gates only the transactions table and export.

**Architecture:** Remove the early-return path in `DashboardController` that sends no data when `view_reports` is absent. Always compute scoped KPIs/chart/appointments; conditionally include transactions. Restructure `Dashboard.vue` to always render KPIs + calendar + chart, gate only the transactions section behind `canReport`.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3 (Composition API, `<script setup>`), Pest/PHPUnit feature tests

---

## File Map

| File | Action | What changes |
|---|---|---|
| `tests/Feature/DashboardTest.php` | Modify | Update one assertion, add one new test |
| `app/Http/Controllers/DashboardController.php` | Modify | Remove early return; always pass `report`; conditional `transactions` |
| `resources/js/Pages/Dashboard.vue` | Modify | Remove top-level `canReport` gate; gate only transactions; hide reminders card when null; remove module launcher |

---

## Task 1: Update tests to capture new expected behaviour

**Files:**
- Modify: `tests/Feature/DashboardTest.php`

The existing `test_technician_without_view_reports_sees_launcher` asserts `.missing('report')`. After our change, `report` will always be present. Update that test and add a new one asserting scoped KPIs arrive even without `view_reports`.

- [ ] **Step 1: Replace the launcher test with the correct expectation**

In `tests/Feature/DashboardTest.php`, replace:

```php
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
```

with:

```php
public function test_technician_without_view_reports_sees_launcher(): void
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
```

- [ ] **Step 2: Add test asserting scoped technician KPIs arrive without view_reports**

Add this test after `test_technician_without_view_reports_sees_launcher`:

```php
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
```

- [ ] **Step 3: Run updated tests — expect failures**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=DashboardTest
```

Expected: `test_technician_without_view_reports_sees_launcher` and `test_technician_kpis_scoped_without_view_reports` **FAIL** (current controller returns no `report` key for non-view_reports users). All other tests pass.

---

## Task 2: Update DashboardController to always send report data

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`

Remove the early return. Always compute scoped KPIs, servicesByType, and appointments. Only populate `transactions` when the user has `view_reports`.

- [ ] **Step 1: Replace the controller method body**

Replace the entire `index` method in `app/Http/Controllers/DashboardController.php` with:

```php
public function index(Request $request, ReportService $reports): Response
{
    $period = $request->input('period');
    if (! in_array($period, ReportService::PERIODS, true)) {
        $period = 'all';
    }

    $month = (string) $request->input('month', '');
    if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = now()->format('Y-m');
    }

    $user      = $request->user();
    $scopeId   = $user->seesAllData() ? null : $user->id;
    $canReport = $user->hasPermission('view_reports');

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
        ],
        'appointments' => Appointment::query()
            ->visibleTo($user)
            ->with('client:id,serial_no,name')
            ->forMonth($month)
            ->orderBy('datetime')
            ->get(),
    ]);
}
```

- [ ] **Step 2: Run tests — all should pass**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=DashboardTest
```

Expected: all tests **PASS**.

- [ ] **Step 3: Run full test suite to check for regressions**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests **PASS**.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/DashboardTest.php app/Http/Controllers/DashboardController.php
git commit -m "feat(dashboard): always send scoped KPIs; gate transactions behind view_reports"
```

---

## Task 3: Restructure Dashboard.vue

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`

Remove the `v-if="canReport"` / `v-else` split that wraps the entire page. Always render KPI cards, calendar, and services chart. Gate only the transactions section. Hide the "Pending Reminders" card when `kpis.pending_reminders === null` (technicians always get null). Remove the module launcher (replaced by a real dashboard for all users).

- [ ] **Step 1: Update the `report` prop default**

In the `<script setup>` block, change:

```js
const props = defineProps({
    canReport: { type: Boolean, default: false },
    period: { type: String, default: 'all' },
    month: { type: String, default: '' },
    report: { type: Object, default: null },        // { kpis, servicesByType, transactions }
    appointments: { type: Array, default: () => [] },
});
```

to:

```js
const props = defineProps({
    canReport: { type: Boolean, default: false },
    period: { type: String, default: 'all' },
    month: { type: String, default: '' },
    report: { type: Object, default: () => ({ kpis: {}, servicesByType: [], transactions: [] }) },
    appointments: { type: Array, default: () => [] },
});
```

- [ ] **Step 2: Replace the entire `<template>` body inside `<AdminLayout>`**

Replace everything between `<AdminLayout>` and `</AdminLayout>` (lines 98–362 in the original) with:

```vue
<AdminLayout>
    <template #header>
        <div>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Dashboard</h1>
            <p class="text-xs text-ink-soft">Revenue, services and reminders at a glance.</p>
        </div>
    </template>

    <!-- ── KPI Stat Cards (always visible, scoped to user's own data) ── -->
    <div
        class="mb-6 grid gap-4 sm:grid-cols-2"
        :class="kpis.pending_reminders !== null ? 'lg:grid-cols-4' : 'lg:grid-cols-3'"
    >
        <StatCard
            label="Total Clients"
            :value="kpis.total_clients ?? 0"
            :sub="`+${kpis.clients_this_month ?? 0} this month`"
            :sub-positive="true"
            variant="primary"
        >
            <template #icon>
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            </template>
        </StatCard>

        <StatCard
            label="Revenue (This Month)"
            :value="money(kpis.revenue_month)"
            :sub="kpis.revenue_mom_pct != null ? `${kpis.revenue_mom_pct >= 0 ? '+' : ''}${kpis.revenue_mom_pct}% vs last month` : 'no prior month'"
            :sub-positive="kpis.revenue_mom_pct != null && kpis.revenue_mom_pct >= 0"
            variant="ok"
        >
            <template #icon>
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23" /><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                </svg>
            </template>
        </StatCard>

        <StatCard
            label="All-time Revenue"
            :value="money(kpis.revenue_all_time)"
            variant="primary"
        >
            <template #icon>
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" />
                </svg>
            </template>
        </StatCard>

        <Link
            v-if="kpis.pending_reminders !== null"
            :href="route('reminders.index')"
            class="block transition hover:no-underline"
        >
            <StatCard
                label="Pending Reminders"
                :value="kpis.pending_reminders ?? 0"
                sub="clients to follow up →"
                variant="warn"
                class="h-full cursor-pointer hover:shadow-lift"
            >
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                </template>
            </StatCard>
        </Link>
    </div>

    <!-- ── Calendar + Services chart (always visible) ── -->
    <div class="mb-5 grid gap-5 lg:grid-cols-3">

        <!-- Calendar + day panel -->
        <div class="space-y-4 lg:col-span-1">
            <MonthCalendar
                :month="month"
                :appointments="appointments"
                :selected-day="selectedDay"
                @select="selectDay"
                @prev="shiftMonth(-1)"
                @next="shiftMonth(1)"
            />
            <div v-if="selectedDay" class="rounded-ral border border-line bg-surface p-4 shadow-card">
                <h3 class="mb-2 text-sm font-bold text-navy-800">Day {{ selectedDay }}</h3>
                <div v-if="dayList.length" class="space-y-2">
                    <div
                        v-for="a in dayList"
                        :key="a.id"
                        class="flex items-center gap-2 rounded-ra bg-surface-muted px-3 py-2 text-[13px]"
                    >
                        <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                        <span class="text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
                    </div>
                </div>
                <p v-else class="py-2 text-center text-sm text-ink-muted">No appointments.</p>
            </div>
        </div>

        <!-- Services by Type — horizontal CSS bars -->
        <div class="rounded-ral border border-line bg-surface p-5 shadow-card lg:col-span-2">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-bold text-navy-800">Services by Type</h2>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="p in PERIODS"
                        :key="p.key"
                        class="rounded-ra px-3 py-1.5 text-xs font-semibold transition"
                        :class="period === p.key
                            ? 'bg-primary text-white shadow-card'
                            : 'bg-surface-muted text-ink-soft hover:bg-primary-50 hover:text-primary'"
                        @click="setPeriod(p.key)"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <div v-if="report.servicesByType.length" class="space-y-4">
                <div v-for="s in report.servicesByType" :key="s.type">
                    <div class="mb-1.5 flex items-center justify-between gap-2 text-[13px]">
                        <span class="font-medium text-ink">{{ s.type }}</span>
                        <span class="font-mono font-semibold text-ink-soft">{{ s.count }}</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-surface-muted">
                        <div
                            class="h-full rounded-full transition-all duration-500"
                            :class="typeBarColor[s.type] ?? 'bg-primary'"
                            :style="{ width: (s.count / maxCount * 100) + '%' }"
                        />
                    </div>
                </div>
            </div>
            <p v-else class="py-8 text-center text-sm text-ink-muted">No services in this period.</p>
        </div>
    </div>

    <!-- ── Recent Transactions (view_reports only) ── -->
    <div v-if="canReport" class="rounded-ral border border-line bg-surface shadow-card">
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <h2 class="text-sm font-bold text-navy-800">Recent Transactions</h2>
            <a
                v-if="can.export_data"
                :href="exportUrl"
                class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" />
                </svg>
                Export CSV
            </a>
        </div>

        <div class="p-4">
            <DataTable
                :columns="txnColumns"
                :rows="txnRows"
                mode="client"
                :searchable="true"
                :search-keys="['client_name', 'serial_no', 'service_type', 'status']"
                search-placeholder="Search transactions…"
                :per-page="10"
            >
                <template #cell-serial_no="{ value }">
                    <span class="font-mono text-xs text-ink-muted">{{ value ?? '—' }}</span>
                </template>

                <template #cell-service_type="{ value }">
                    <Badge :variant="serviceVariant(value)">{{ value }}</Badge>
                </template>

                <template #cell-amount="{ row }">
                    <span class="font-mono font-semibold text-ink">{{ row.amount_fmt }}</span>
                </template>

                <template #cell-method="{ value }">
                    <span class="text-ink-soft">{{ value ?? '—' }}</span>
                </template>

                <template #cell-status="{ value }">
                    <Badge :variant="statusVariant(value)">{{ value }}</Badge>
                </template>

                <template #empty>No transactions in this period.</template>

                <template #card="{ row }">
                    <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="font-semibold text-ink">{{ row.client_name }}</span>
                            <Badge :variant="statusVariant(row.status)">{{ row.status }}</Badge>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-xs text-ink-muted">
                            <span class="font-mono">{{ row.serial_no ?? '—' }}</span>
                            <Badge :variant="serviceVariant(row.service_type)">{{ row.service_type }}</Badge>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <span class="text-xs text-ink-soft">{{ row.date_fmt }}</span>
                            <span class="font-mono font-semibold text-ink">{{ row.amount_fmt }}</span>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>
</AdminLayout>
```

- [ ] **Step 3: Start dev server and verify visually**

```bash
npm run dev
```

Check two accounts:
1. **Technician (no `view_reports`)** — should see KPI cards (3 cards, no reminders), calendar, services chart. No transactions table.
2. **Admin** — should see all 4 KPI cards, calendar, services chart, transactions table, export button.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "feat(dashboard): show own KPIs to all users; gate transactions behind view_reports"
```
