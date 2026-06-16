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

const totalPaid = computed(() =>
    props.transactions.filter((t) => t.status === 'paid').reduce((sum, t) => sum + t.amount, 0)
);

const pendingCount = computed(() =>
    props.transactions.filter((t) => t.status === 'pending').length
);

const pendingAmount = computed(() =>
    props.transactions.filter((t) => t.status === 'pending').reduce((sum, t) => sum + t.amount, 0)
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
    props.transactions.map((t) => ({
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
            <div class="flex items-center justify-between gap-4">
                <h1 class="text-base font-bold text-navy-800">Transactions</h1>
                <div class="flex gap-1">
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
            </div>
        </template>

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
            <div class="border-b border-line px-5 py-3">
                <h2 class="text-sm font-bold text-navy-800">All Transactions</h2>
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
