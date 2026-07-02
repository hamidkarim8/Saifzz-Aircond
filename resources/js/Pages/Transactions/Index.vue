<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import DataTable from '@/Components/DataTable.vue';
import Badge from '@/Components/Badge.vue';
import { serviceVariant, statusVariant } from '@/lib/badges';

const props = defineProps({
    transactions: { type: Array, default: () => [] },
    period: { type: String, default: 'all' },
    periods: { type: Array, default: () => [] },
    dateFrom: { type: String, default: null },
    dateTo: { type: String, default: null },
});

const money = (v) => 'RM ' + Number(v ?? 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day} ${months[+m - 1]} ${y}`;
};

const PERIOD_LABELS = { all: 'All', month: 'Month', week: 'Week', today: 'Today' };

const setPeriod = (p) => {
    router.get(route('transactions.index'), { period: p }, { preserveState: true, replace: true });
};

const rangeFrom = ref(props.dateFrom);
const rangeTo = ref(props.dateTo);

const applyRange = () => {
    if (!rangeFrom.value || !rangeTo.value) return;
    router.get(route('transactions.index'), { date_from: rangeFrom.value, date_to: rangeTo.value }, { preserveState: true, replace: true });
};

const clearRange = () => {
    rangeFrom.value = null;
    rangeTo.value = null;
    setPeriod('all');
};

// FEAT-008 / FEAT-009 — client-side filters (full list is already loaded for the period).
const METHODS = ['Cash', 'DuitNow QR', 'Manual QR'];
const STATUSES = ['paid', 'pending', 'failed', 'cancelled', 'void'];

const methodFilter = ref('all');
const statusFilter = ref('all');

const filtered = computed(() =>
    props.transactions.filter((t) =>
        (methodFilter.value === 'all' || t.method === methodFilter.value) &&
        (statusFilter.value === 'all' || t.status === statusFilter.value)
    )
);

const totalPaid = computed(() =>
    filtered.value.filter((t) => t.status === 'paid').reduce((sum, t) => sum + t.amount, 0)
);

const pendingCount = computed(() =>
    filtered.value.filter((t) => t.status === 'pending').length
);

const pendingAmount = computed(() =>
    filtered.value.filter((t) => t.status === 'pending').reduce((sum, t) => sum + t.amount, 0)
);

const columns = [
    { key: 'date',         label: 'Date',         sortable: true },
    { key: 'client_name',  label: 'Client',        sortable: true },
    { key: 'serial_no',    label: 'Serial #',      sortable: false },
    { key: 'service_type', label: 'Service Type',  sortable: true },
    { key: 'method',       label: 'Method',        sortable: false },
    { key: 'amount',       label: 'Amount',        sortable: true, align: 'right' },
    { key: 'status',       label: 'Status',        sortable: false },
];

const rows = computed(() =>
    filtered.value.map((t) => ({
        ...t,
        date_fmt: fmtDate(t.date),
        amount_fmt: money(t.amount),
    }))
);
</script>

<template>
    <Head title="Transactions" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Transactions</h1>
        </template>

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="p in periods"
                    :key="p"
                    class="rounded-ra px-3 py-1.5 text-xs font-semibold transition"
                    :class="period === p
                        ? 'bg-primary text-white shadow-card'
                        : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                    @click="setPeriod(p)"
                >
                    {{ PERIOD_LABELS[p] ?? p }}
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <input v-model="rangeFrom" type="date" class="rounded-ra border-line py-1 text-xs shadow-card focus:border-primary focus:ring-primary" />
                <span class="text-xs text-ink-muted">to</span>
                <input v-model="rangeTo" type="date" class="rounded-ra border-line py-1 text-xs shadow-card focus:border-primary focus:ring-primary" />
                <button
                    class="rounded-ra border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink-soft shadow-card transition hover:bg-surface-muted hover:text-ink"
                    @click="applyRange"
                >Apply</button>
                <button
                    v-if="dateFrom || dateTo"
                    class="text-xs font-medium text-ink-muted hover:text-ink transition"
                    @click="clearRange"
                >Clear</button>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Total Paid"
                :value="money(totalPaid)"
                variant="ok"
            />
            <StatCard
                label="Paid This Period"
                :value="money(totalPaid)"
                variant="primary"
            />
            <StatCard
                label="Pending Count"
                :value="pendingCount"
                variant="warn"
            />
            <StatCard
                label="Pending Amount"
                :value="money(pendingAmount)"
                variant="warn"
            />
        </div>

        <div class="rounded-ral border border-line bg-surface shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <h2 class="text-sm font-bold text-navy-800">All Transactions</h2>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-1">
                        <span class="mr-1 text-xs font-semibold text-ink-muted">Method</span>
                        <button
                            v-for="m in ['all', ...METHODS]"
                            :key="m"
                            class="rounded-ra px-2.5 py-1 text-xs font-semibold capitalize transition"
                            :class="methodFilter === m
                                ? 'bg-primary text-white shadow-card'
                                : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                            @click="methodFilter = m"
                        >
                            {{ m === 'all' ? 'All' : m }}
                        </button>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="mr-1 text-xs font-semibold text-ink-muted">Status</span>
                        <button
                            v-for="s in ['all', ...STATUSES]"
                            :key="s"
                            class="rounded-ra px-2.5 py-1 text-xs font-semibold capitalize transition"
                            :class="statusFilter === s
                                ? 'bg-primary text-white shadow-card'
                                : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                            @click="statusFilter = s"
                        >
                            {{ s === 'all' ? 'All' : s }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-4">
                <DataTable
                    :columns="columns"
                    :rows="rows"
                    mode="client"
                    :searchable="true"
                    :search-keys="['client_name', 'serial_no', 'service_type', 'status', 'method']"
                    search-placeholder="Search transactions…"
                    :per-page="20"
                >
                    <template #cell-date="{ row }">
                        <span class="text-ink-soft">{{ row.date_fmt }}</span>
                    </template>

                    <template #cell-client_name="{ value }">
                        <span class="font-medium text-ink">{{ value }}</span>
                    </template>

                    <template #cell-serial_no="{ value }">
                        <span class="font-mono text-xs text-ink-muted">{{ value ?? '—' }}</span>
                    </template>

                    <template #cell-service_type="{ value }">
                        <Badge :variant="serviceVariant(value)">{{ value ?? '—' }}</Badge>
                    </template>

                    <template #cell-method="{ value }">
                        <Badge v-if="value" :variant="value === 'cash' ? 'blue' : 'indigo'" class="capitalize">{{ value }}</Badge>
                        <span v-else class="text-ink-muted">—</span>
                    </template>

                    <template #cell-amount="{ row }">
                        <span class="font-mono font-semibold text-ink">{{ row.amount_fmt }}</span>
                    </template>

                    <template #cell-status="{ value }">
                        <Badge :variant="statusVariant(value)" class="capitalize">{{ value }}</Badge>
                    </template>

                    <template #empty>No transactions in this period.</template>

                    <template #card="{ row }">
                        <div class="rounded-ral border border-line bg-surface p-4 shadow-card">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <span class="font-semibold text-ink">{{ row.client_name }}</span>
                                <Badge :variant="statusVariant(row.status)" class="capitalize">{{ row.status }}</Badge>
                            </div>
                            <div class="flex items-center justify-between gap-2 text-xs text-ink-muted">
                                <span class="font-mono">{{ row.serial_no ?? '—' }}</span>
                                <Badge :variant="serviceVariant(row.service_type)">{{ row.service_type }}</Badge>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <span class="text-xs text-ink-soft">{{ row.date_fmt }}</span>
                                <span class="font-mono font-semibold text-ink">{{ row.amount_fmt }}</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                <Badge v-if="row.method" :variant="row.method === 'cash' ? 'blue' : 'indigo'" class="capitalize">{{ row.method }}</Badge>
                                <span v-else class="text-ink-muted">—</span>
                            </div>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AdminLayout>
</template>
