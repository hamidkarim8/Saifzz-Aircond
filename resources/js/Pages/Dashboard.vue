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
    report: { type: Object, default: null },        // { kpis, servicesByType, transactions }
    appointments: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const can = computed(() => page.props.auth.can ?? {});

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

// ── DataTable columns for transactions ──
const txnColumns = [
    { key: 'client_name', label: 'Client', sortable: true },
    { key: 'serial_no',   label: 'Serial',  sortable: false },
    { key: 'service_type',label: 'Service', sortable: true },
    { key: 'amount',      label: 'Amount',  sortable: true, align: 'right' },
    { key: 'method',      label: 'Payment', sortable: false },
    { key: 'status',      label: 'Status',  sortable: false },
];

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
            <div>
                <h1 class="text-lg font-bold tracking-tight text-navy-800">Dashboard</h1>
                <p class="text-xs text-ink-soft">Revenue, services and reminders at a glance.</p>
            </div>
        </template>

        <!-- ══ Reporting dashboard (view_reports) ══ -->
        <template v-if="canReport">

            <!-- ── 4 KPI Stat Cards ── -->
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

                <Link :href="route('reminders.index')" class="block transition hover:no-underline">
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

            <!-- ── Middle row: Calendar + Services chart ── -->
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
                    <!-- Header: title + period filter -->
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

            <!-- ── Recent Transactions ── -->
            <div class="rounded-ral border border-line bg-surface shadow-card">
                <!-- Table header: title + Export button -->
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
                        <!-- Serial: monospace muted -->
                        <template #cell-serial_no="{ value }">
                            <span class="font-mono text-xs text-ink-muted">{{ value ?? '—' }}</span>
                        </template>

                        <!-- Service: Badge -->
                        <template #cell-service_type="{ value }">
                            <Badge :variant="serviceVariant(value)">{{ value }}</Badge>
                        </template>

                        <!-- Amount: monospace bold -->
                        <template #cell-amount="{ row }">
                            <span class="font-mono font-semibold text-ink">{{ row.amount_fmt }}</span>
                        </template>

                        <!-- Payment method: muted -->
                        <template #cell-method="{ value }">
                            <span class="text-ink-soft">{{ value ?? '—' }}</span>
                        </template>

                        <!-- Status: Badge -->
                        <template #cell-status="{ value }">
                            <Badge :variant="statusVariant(value)">{{ value }}</Badge>
                        </template>

                        <template #empty>No transactions in this period.</template>

                        <!-- Mobile card slot -->
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
        </template>

        <!-- ══ Module launcher (no view_reports) ══ -->
        <div v-else class="rounded-ral border border-line bg-surface p-8 shadow-card">
            <p class="font-mono text-xs uppercase tracking-widest text-primary">Saifzz Aircond</p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-navy-800">Welcome back, {{ user?.name }}.</h2>
            <p class="mt-2 text-sm text-ink-soft">Jump into the modules you have access to.</p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-if="can.view_clients"
                    :href="route('clients.index')"
                    class="group rounded-ral border border-line bg-surface-muted p-5 transition hover:border-primary hover:shadow-card"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-ra bg-primary text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" />
                        </svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink group-hover:text-primary">Clients</div>
                    <div class="mt-1 text-sm text-ink-soft">Registry, search, profiles & history.</div>
                </Link>

                <Link
                    v-if="can.record_service"
                    :href="route('service-records.index')"
                    class="group rounded-ral border border-line bg-surface-muted p-5 transition hover:border-primary hover:shadow-card"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-ra bg-primary text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" />
                        </svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink group-hover:text-primary">Service Records</div>
                    <div class="mt-1 text-sm text-ink-soft">Record a visit and take payment.</div>
                </Link>

                <Link
                    v-if="can.set_appointment"
                    :href="route('appointments.index')"
                    class="group rounded-ral border border-line bg-surface-muted p-5 transition hover:border-primary hover:shadow-card"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-ra bg-primary text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
                        </svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink group-hover:text-primary">Appointments</div>
                    <div class="mt-1 text-sm text-ink-soft">Schedule and track visits.</div>
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
