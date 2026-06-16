<script setup>
import { ref, computed, h } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import MonthCalendar from './Appointments/Partials/MonthCalendar.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

const props = defineProps({
    canReport: { type: Boolean, default: false },
    period: { type: String, default: 'all' },
    month: { type: String, default: '' },
    report: { type: Object, default: () => ({ kpis: {}, servicesByType: [], transactions: [], receivables: null }) },
    appointments: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const can = computed(() => page.props.auth.can ?? {});
const seesAllData = computed(() => can.value.view_all_data);
const roleLabel = (r) => r ? r.charAt(0).toUpperCase() + r.slice(1) : '';

// ── Formatting ──
const money = (v) => 'RM ' + Number(v ?? 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day} ${months[+m - 1]} ${y}`;
};

// ── Period filter (server round-trip, keeps the calendar month) ──
const PERIODS = [
    { key: 'all', label: 'All time' },
    { key: 'month', label: 'This month' },
    { key: 'week', label: 'This week' },
    { key: 'today', label: 'Today' },
];
const setPeriod = (key) => {
    router.get(route('dashboard'), { period: key, month: props.month }, { preserveState: false, preserveScroll: true });
};

// ── Mini-calendar month nav (keeps the period) ──
const shiftMonth = (delta) => {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(route('dashboard'), { period: props.period, month: next }, { preserveState: false });
};

const selectedDay = ref(null);
const selectDay = (day) => { selectedDay.value = selectedDay.value === day ? null : day; };
const dayList = computed(() => {
    if (!selectedDay.value) return [];
    return props.appointments.filter((a) => new Date(a.datetime).getDate() === selectedDay.value);
});
const fmtTime = (dt) => (dt ?? '').slice(11, 16);

// ── Services-by-type bars ──
const maxCount = computed(() => Math.max(1, ...(props.report?.servicesByType ?? []).map((s) => s.count)));

// Map service type to a Tailwind bg color (CSS bar fill)
const typeBarColor = {
    Cleaning: 'bg-primary',
    'Gas Top-Up': 'bg-warn',
    Repair: 'bg-danger',
    Installation: 'bg-ok',
    Troubleshoot: 'bg-invoice',
};

// ── KPIs ──
const kpis = computed(() => props.report?.kpis ?? {});
const exportUrl = computed(() => route('reports.transactions.export', { period: props.period }));

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

// ── DataTable columns for transactions ──
const txnColumns = computed(() => [
    { key: 'client_name', label: 'Client', sortable: true },
    { key: 'serial_no',   label: 'Serial',  sortable: false },
    { key: 'service_type',label: 'Service', sortable: true },
    { key: 'amount',      label: 'Amount',  sortable: true, align: 'right' },
    { key: 'method',      label: 'Payment', sortable: false },
    { key: 'status',      label: 'Status',  sortable: false },
    // Admins (all-data) see who recorded each transaction.
    ...(seesAllData.value ? [{ key: 'created_by', label: 'Created by', sortable: true }] : []),
]);

// Flatten transactions for DataTable rows (add formatted amount)
const txnRows = computed(() =>
    (props.report?.transactions ?? []).map((t) => ({
        ...t,
        amount_fmt: money(t.amount),
        date_fmt: fmtDate(t.date),
    }))
);
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Dashboard</h1>
        </template>

    <!-- ── KPI Stat Cards (always visible, scoped to user's own data) ── -->
    <div
        class="mb-6 grid gap-4 sm:grid-cols-2"
        :class="canReport
            ? (kpis.pending_reminders !== null ? 'lg:grid-cols-4' : 'lg:grid-cols-3')
            : 'lg:grid-cols-1'"
    >
        <StatCard
            v-if="canReport"
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
            v-if="canReport"
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
            v-if="canReport"
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
                        <span class="text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
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
                :search-keys="['client_name', 'serial_no', 'service_type', 'status', 'created_by']"
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

                <template #cell-created_by="{ row }">
                    <span v-if="row.created_by" class="text-ink">
                        {{ row.created_by }}
                        <span class="text-xs text-ink-muted">({{ roleLabel(row.created_by_role) }})</span>
                    </span>
                    <span v-else class="text-ink-muted">—</span>
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
                        <div v-if="seesAllData && row.created_by" class="mt-1 text-xs text-ink-muted">
                            By {{ row.created_by }} ({{ roleLabel(row.created_by_role) }})
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>

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
            <!-- Desktop table -->
            <div v-if="report.receivables.items.length" class="hidden overflow-x-auto md:block">
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

            <!-- Mobile cards -->
            <div v-if="report.receivables.items.length" class="divide-y divide-line md:hidden">
                <Link
                    v-for="item in report.receivables.items"
                    :key="item.txn_id"
                    :href="route('service-records.show', item.visit_id)"
                    class="block px-5 py-4 transition hover:bg-surface-muted"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-ink">{{ item.client_name }}</div>
                            <div class="mt-0.5 font-mono text-xs text-ink-muted">{{ item.serial_no ?? '—' }} · {{ fmtDate(item.visit_date) }}</div>
                        </div>
                        <span class="font-mono font-semibold text-ink">{{ money(item.amount) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <span
                            class="rounded-ra px-2 py-1 text-xs font-semibold"
                            :class="agingBadgeClass(item.days_outstanding)"
                        >{{ item.days_outstanding }}d outstanding</span>
                        <span class="text-xs font-semibold text-primary">View →</span>
                    </div>
                </Link>
            </div>

            <p v-if="!report.receivables.items.length" class="px-5 py-8 text-center text-sm text-ink-muted">No outstanding payments.</p>
        </div>
    </div>
    </AdminLayout>
</template>
