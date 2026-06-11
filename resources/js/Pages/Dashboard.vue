<script setup>
import { ref, computed } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MonthCalendar from './Appointments/Partials/MonthCalendar.vue';

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
const typeColor = {
    Cleaning: 'bg-primary',
    'Gas Top-Up': 'bg-warn',
    Repair: 'bg-danger',
    Installation: 'bg-ok',
    Troubleshoot: 'bg-invoice',
};

const statusBadge = {
    paid: 'bg-ok-bg text-ok',
    pending: 'bg-warn-bg text-warn',
    failed: 'bg-danger-bg text-danger',
};

const kpis = computed(() => props.report?.kpis ?? {});
const exportUrl = computed(() => route('reports.transactions.export', { period: props.period }));
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
            <!-- KPI cards -->
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Total Clients</div>
                    <div class="mt-1 text-2xl font-bold text-navy-800">{{ kpis.total_clients ?? 0 }}</div>
                    <div class="mt-1 text-xs text-ok">+{{ kpis.clients_this_month ?? 0 }} this month</div>
                </div>
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Revenue (this month)</div>
                    <div class="mt-1 text-2xl font-bold text-primary">{{ money(kpis.revenue_month) }}</div>
                    <div v-if="kpis.revenue_mom_pct !== null && kpis.revenue_mom_pct !== undefined" class="mt-1 text-xs" :class="kpis.revenue_mom_pct >= 0 ? 'text-ok' : 'text-danger'">
                        {{ kpis.revenue_mom_pct >= 0 ? '+' : '' }}{{ kpis.revenue_mom_pct }}% vs last month
                    </div>
                    <div v-else class="mt-1 text-xs text-ink-muted">no prior month</div>
                </div>
                <div class="rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">All-time Revenue</div>
                    <div class="mt-1 text-2xl font-bold text-navy-800">{{ money(kpis.revenue_all_time) }}</div>
                </div>
                <Link :href="route('reminders.index')" class="rounded-ral border border-line bg-surface p-5 shadow-card transition hover:border-primary">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Pending Reminders</div>
                    <div class="mt-1 text-2xl font-bold text-warn">{{ kpis.pending_reminders ?? 0 }}</div>
                    <div class="mt-1 text-xs text-ink-soft">clients to follow up →</div>
                </Link>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- Calendar + day panel -->
                <div class="lg:col-span-1 space-y-4">
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
                            <div v-for="a in dayList" :key="a.id" class="flex items-center gap-2 rounded-ra bg-surface-muted px-3 py-2 text-[13px]">
                                <span class="font-mono font-semibold text-primary">{{ fmtTime(a.datetime) }}</span>
                                <span class="text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
                            </div>
                        </div>
                        <p v-else class="py-2 text-center text-sm text-ink-muted">No appointments.</p>
                    </div>
                </div>

                <!-- Services by type -->
                <div class="lg:col-span-2 rounded-ral border border-line bg-surface p-5 shadow-card">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-sm font-bold text-navy-800">Services by Type</h2>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="p in PERIODS"
                                :key="p.key"
                                class="rounded-ra px-3 py-1.5 text-xs font-semibold transition"
                                :class="period === p.key ? 'bg-primary text-white' : 'bg-surface-muted text-ink-soft hover:bg-primary-50'"
                                @click="setPeriod(p.key)"
                            >
                                {{ p.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="report.servicesByType.length" class="space-y-3">
                        <div v-for="s in report.servicesByType" :key="s.type">
                            <div class="mb-1 flex justify-between text-[13px]">
                                <span class="font-medium text-ink">{{ s.type }}</span>
                                <span class="font-mono font-semibold text-ink-soft">{{ s.count }}</span>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-surface-muted">
                                <div class="h-full rounded-full" :class="typeColor[s.type] ?? 'bg-primary'" :style="{ width: (s.count / maxCount * 100) + '%' }" />
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-8 text-center text-sm text-ink-muted">No services in this period.</p>
                </div>
            </div>

            <!-- Recent transactions -->
            <div class="mt-5 rounded-ral border border-line bg-surface shadow-card">
                <div class="flex items-center justify-between border-b border-line px-5 py-3">
                    <h2 class="text-sm font-bold text-navy-800">Transactions</h2>
                    <a
                        v-if="can.export_data"
                        :href="exportUrl"
                        class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        Export CSV
                    </a>
                </div>
                <div v-if="report.transactions.length" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-muted">
                            <tr class="border-b border-line">
                                <th class="px-5 py-2.5 font-semibold">Txn</th>
                                <th class="px-5 py-2.5 font-semibold">Date</th>
                                <th class="px-5 py-2.5 font-semibold">Client</th>
                                <th class="px-5 py-2.5 text-right font-semibold">Amount</th>
                                <th class="px-5 py-2.5 font-semibold">Method</th>
                                <th class="px-5 py-2.5 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in report.transactions" :key="t.txn_id" class="border-b border-line/60 last:border-0">
                                <td class="px-5 py-2.5 font-mono text-xs text-ink-soft">{{ t.txn_id }}</td>
                                <td class="px-5 py-2.5 text-ink">{{ fmtDate(t.date) }}</td>
                                <td class="px-5 py-2.5">
                                    <span class="text-ink">{{ t.client_name }}</span>
                                    <span class="ml-1 font-mono text-xs text-ink-muted">#{{ t.serial_no }}</span>
                                </td>
                                <td class="px-5 py-2.5 text-right font-mono font-semibold text-ink">{{ money(t.amount) }}</td>
                                <td class="px-5 py-2.5 text-ink-soft">{{ t.method ?? '—' }}</td>
                                <td class="px-5 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadge[t.status]">{{ t.status }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="py-8 text-center text-sm text-ink-muted">No transactions in this period.</p>
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" /></svg>
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" /></svg>
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" /></svg>
                    </div>
                    <div class="mt-3 font-semibold text-ink group-hover:text-primary">Appointments</div>
                    <div class="mt-1 text-sm text-ink-soft">Schedule and track visits.</div>
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
